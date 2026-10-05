<?php

namespace App\Application\Community;

use App\Application\Support\GameAction;
use App\Http\View\UiColors;
use App\Models\BoardMessage;
use App\Models\User;

final class CommunityService
{
    public const KEEP_MESSAGES = 50;

    public function __construct(private GameAction $actions) {}

    public function post(int $userId, string $key, string $body): array
    {
        $body = trim(preg_replace('/\s+/u', ' ', $body));

        return $this->actions->execute($userId, 'community.post', $key, compact('body'), function (User $user) use ($body) {
            $this->actions->ensure(mb_strlen($body) >= 1 && mb_strlen($body) <= 200, 'Use 1–200 characters.');
            $color = strtolower((string) ($user->preferences['color'] ?? ''));
            $message = BoardMessage::create([
                'user_id' => $user->id, 'author_name' => $user->name,
                'author_color' => UiColors::valid($color) ? $color : '', 'body' => $body,
            ]);
            $keep = BoardMessage::orderByDesc('id')->limit(self::KEEP_MESSAGES)->pluck('id');
            BoardMessage::whereNotIn('id', $keep)->delete();

            return ['message_id' => $message->id];
        });
    }
}
