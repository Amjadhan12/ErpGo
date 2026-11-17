<?php

namespace Tests\Feature;

use App\Models\BusinessPartner;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_secretary_gets_one_latest_unread_preview_per_customer_or_supplier(): void
    {
        $secretary = User::factory()->create([
            'role' => 'Secretary',
            'is_active' => true,
        ]);
        $customer = $this->createPartnerUser('Customer', 'Customer Chat');
        $supplier = $this->createPartnerUser('Supplier', 'Supplier Chat');

        $this->createMessage($customer['partner'], $customer['user'], $secretary, 'Earlier customer message');
        $latestCustomerMessage = $this->createMessage(
            $customer['partner'],
            $customer['user'],
            $secretary,
            'Latest customer message'
        );
        $this->createMessage($supplier['partner'], $supplier['user'], $secretary, 'Supplier message');
        $readMessage = $this->createMessage(
            $supplier['partner'],
            $supplier['user'],
            $secretary,
            'Already read message'
        );
        $readMessage->update(['read_at' => now()]);

        $response = $this->actingAs($secretary, 'sanctum')
            ->getJson('/api/chat/notifications')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 3)
            ->assertJsonCount(2, 'data.notifications');

        $notifications = $response->json('data.notifications');
        $customerNotification = collect($notifications)->firstWhere(
            'business_partner_id',
            $customer['partner']->id
        );

        $this->assertSame([
            'id',
            'business_partner_id',
            'partner_name',
            'partner_type',
            'sender',
            'message',
            'created_at',
        ], array_keys($customerNotification));
        $this->assertSame($latestCustomerMessage->id, $customerNotification['id']);
        $this->assertSame('Customer Chat', $customerNotification['partner_name']);
        $this->assertSame('Customer', $customerNotification['partner_type']);
        $this->assertSame('Latest customer message', $customerNotification['message']);
        $this->assertSame([
            'id' => $customer['user']->id,
            'name' => $customer['user']->name,
            'role' => 'Customer',
        ], $customerNotification['sender']);
        $this->assertIsString($customerNotification['created_at']);
        $this->assertNotNull($customerNotification['created_at']);

        $this->actingAs($secretary, 'sanctum')
            ->getJson('/api/chat/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 3);
    }

    public function test_customer_and_supplier_notifications_are_limited_to_their_valid_conversation(): void
    {
        $secretary = User::factory()->create([
            'role' => 'Secretary',
            'is_active' => true,
        ]);
        $otherSecretary = User::factory()->create([
            'role' => 'Secretary',
            'is_active' => true,
        ]);

        foreach (['Customer', 'Supplier'] as $role) {
            $recipient = $this->createPartnerUser($role, $role.' Recipient');
            $otherPartner = $this->createPartnerUser(
                $role === 'Customer' ? 'Supplier' : 'Customer',
                'Other Partner'
            );
            $wrongRoleSender = User::factory()->create([
                'role' => 'Admin',
                'is_active' => true,
            ]);

            $validMessage = $this->createMessage(
                $recipient['partner'],
                $secretary,
                $recipient['user'],
                'Valid secretary message'
            );
            $this->createMessage(
                $otherPartner['partner'],
                $otherPartner['user'],
                $recipient['user'],
                'Mismatched partner message'
            );
            $this->createMessage(
                $recipient['partner'],
                $wrongRoleSender,
                $recipient['user'],
                'Wrong sender role message'
            );
            $this->createMessage(
                $otherPartner['partner'],
                $otherPartner['user'],
                $otherSecretary,
                'Message for another recipient'
            );

            $response = $this->actingAs($recipient['user'], 'sanctum')
                ->getJson('/api/chat/notifications')
                ->assertOk()
                ->assertJsonPath('data.unread_count', 3)
                ->assertJsonCount(1, 'data.notifications');

            $notification = $response->json('data.notifications.0');
            $this->assertSame($validMessage->id, $notification['id']);
            $this->assertSame($recipient['partner']->id, $notification['business_partner_id']);
            $this->assertSame('Valid secretary message', $notification['message']);
            $this->assertSame('Secretary', $notification['sender']['role']);
        }
    }

    public function test_notifications_require_authentication_and_valid_partner_role_scope(): void
    {
        $this->getJson('/api/chat/notifications')->assertUnauthorized();

        $partner = BusinessPartner::create([
            'partner_type' => 'Supplier',
            'name' => 'Mismatched Partner',
            'is_active' => true,
        ]);
        $customer = User::factory()->create([
            'role' => 'Customer',
            'business_partner_id' => $partner->id,
            'is_active' => true,
        ]);

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/chat/notifications')
            ->assertForbidden();
    }

    /**
     * @return array{partner: BusinessPartner, user: User}
     */
    private function createPartnerUser(string $role, string $name): array
    {
        $partner = BusinessPartner::create([
            'partner_type' => $role,
            'name' => $name,
            'is_active' => true,
        ]);
        $user = User::factory()->create([
            'name' => $name.' User',
            'role' => $role,
            'business_partner_id' => $partner->id,
            'is_active' => true,
        ]);

        return ['partner' => $partner, 'user' => $user];
    }

    private function createMessage(
        BusinessPartner $partner,
        User $sender,
        User $recipient,
        string $text
    ): ChatMessage {
        return ChatMessage::create([
            'business_partner_id' => $partner->id,
            'sender_id' => $sender->id,
            'recipient_id' => $recipient->id,
            'message' => $text,
        ]);
    }
}
