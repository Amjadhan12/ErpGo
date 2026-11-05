<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessPartner;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    // Get conversation messages
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'business_partner_id' => ['nullable', 'integer', 'exists:business_partners,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = ChatMessage::with([
            'sender:id,name,role',
            'recipient:id,name,role',
        ]);

        if ($user->role === 'Secretary') {
            if (empty($validated['business_partner_id'])) {
                return response()->json([
                    'message' => 'Please select a customer or supplier conversation.',
                ], 422);
            }

            $query->where(
                'business_partner_id',
                $validated['business_partner_id']
            );
        } else {
            $partner = $user->businessPartner;

            if (
                !$partner ||
                !$partner->is_active ||
                $partner->partner_type !== $user->role
            ) {
                return response()->json([
                    'message' => 'Your business partner account is invalid.',
                ], 403);
            }

            $query->where('business_partner_id', $partner->id);
        }

        $messages = $query
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 30);

        return response()->json([
            'message' => 'Chat messages retrieved successfully.',
            'data' => $messages,
        ]);
    }

    // Send a message
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:10000'],
           'business_partner_id' => $user->role === 'Secretary'
    ? ['required', 'integer', 'exists:business_partners,id']
    : ['prohibited'],
        ]);

        if ($user->role === 'Secretary') {
            $partner = BusinessPartner::whereKey(
                $validated['business_partner_id']
            )
                ->whereIn('partner_type', ['Customer', 'Supplier'])
                ->where('is_active', true)
                ->first();

            if (!$partner) {
                return response()->json([
                    'message' => 'Customer or supplier not found or inactive.',
                ], 404);
            }

            $recipient = User::where('business_partner_id', $partner->id)
                ->where('role', $partner->partner_type)
                ->where('is_active', true)
                ->first();

            if (!$recipient) {
                return response()->json([
                    'message' => 'This partner does not have an active login account.',
                ], 404);
            }
        } else {
            $partner = $user->businessPartner;

            if (
                !$partner ||
                !$partner->is_active ||
                $partner->partner_type !== $user->role
            ) {
                return response()->json([
                    'message' => 'Your business partner account is invalid.',
                ], 403);
            }

            $recipient = User::where('role', 'Secretary')
                ->where('is_active', true)
                ->first();

            if (!$recipient) {
                return response()->json([
                    'message' => 'No active secretary account is available.',
                ], 404);
            }
        }

        $chatMessage = ChatMessage::create([
            'business_partner_id' => $partner->id,
            'sender_id' => $user->id,
            'recipient_id' => $recipient->id,
            'message' => $validated['message'],
        ]);

        $chatMessage->load([
            'sender:id,name,role',
            'recipient:id,name,role',
        ]);

        return response()->json([
            'message' => 'Message sent successfully.',
            'data' => $chatMessage,
        ], 201);
    }

    // Mark a received message as read
    public function markAsRead(Request $request, ChatMessage $chatMessage): JsonResponse
    {
        if ($chatMessage->recipient_id !== $request->user()->id) {
            return response()->json([
                'message' => 'You are not authorized to read this message.',
            ], 403);
        }

        if (!$chatMessage->read_at) {
            $chatMessage->update([
                'read_at' => now(),
            ]);
        }

        return response()->json([
            'message' => 'Message marked as read.',
            'data' => $chatMessage->fresh(),
        ]);
    }

    // Get unread messages count
    public function unreadCount(Request $request): JsonResponse
    {
        $count = ChatMessage::where(
            'recipient_id',
            $request->user()->id
        )
            ->whereNull('read_at')
            ->count();

        return response()->json([
            'message' => 'Unread messages count retrieved successfully.',
            'data' => [
                'unread_count' => $count,
            ],
        ]);
    }
}