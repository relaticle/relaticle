<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Enums;

enum EmailShortcut: string
{
    case Next = 'next';
    case Previous = 'previous';
    case Open = 'open';
    case Close = 'close';
    case Reply = 'reply';
    case ReplyAll = 'reply_all';
    case Forward = 'forward';
    case Done = 'done';
    case MarkUnread = 'mark_unread';
    case Select = 'select';
    case FocusSearch = 'focus_search';
    case Compose = 'compose';
    case Help = 'help';
    case Undo = 'undo';

    /** @return list<string> keys in `KeyboardEvent.key` form */
    public function keys(): array
    {
        return match ($this) {
            self::Next => ['j'],
            self::Previous => ['k'],
            self::Open => ['o'],
            self::Close => ['Escape'],
            self::Reply => ['r'],
            self::ReplyAll => ['a'],
            self::Forward => ['f'],
            self::Done => ['e'],
            self::MarkUnread => ['u'],
            self::Select => ['x'],
            self::FocusSearch => ['/'],
            self::Compose => ['c'],
            self::Help => ['?'],
            self::Undo => ['z'],
        };
    }

    /** @return list<ShortcutScope> */
    public function scopes(): array
    {
        return match ($this) {
            self::Next, self::Previous, self::Done, self::MarkUnread => [ShortcutScope::List, ShortcutScope::Reader],
            self::Open, self::Select, self::FocusSearch => [ShortcutScope::List],
            self::Close => [ShortcutScope::List, ShortcutScope::Reader, ShortcutScope::Composer],
            self::Reply, self::ReplyAll, self::Forward => [ShortcutScope::Reader],
            self::Compose, self::Help, self::Undo => [ShortcutScope::Global],
        };
    }

    public function label(): string
    {
        return __("filament/pages/email-inbox.shortcuts.labels.{$this->value}");
    }

    public function action(): string
    {
        return match ($this) {
            self::Next => 'next',
            self::Previous => 'previous',
            self::Open => 'open',
            self::Close => 'close',
            self::Reply => 'reply',
            self::ReplyAll => 'replyAll',
            self::Forward => 'forward',
            self::Done => 'done',
            self::MarkUnread => 'markUnread',
            self::Select => 'select',
            self::FocusSearch => 'focusSearch',
            self::Compose => 'compose',
            self::Help => 'help',
            self::Undo => 'undo',
        };
    }

    // The off switch disables exactly these: one printable character with no modifier.
    public function isCharacterKey(): bool
    {
        return match ($this) {
            self::Close => false,
            self::Next, self::Previous, self::Open, self::Reply, self::ReplyAll, self::Forward, self::Done,
            self::MarkUnread, self::Select, self::FocusSearch, self::Compose, self::Help, self::Undo => true,
        };
    }

    public function firesInEditors(): bool
    {
        return match ($this) {
            self::Close => true,
            self::Next, self::Previous, self::Open, self::Reply, self::ReplyAll, self::Forward, self::Done,
            self::MarkUnread, self::Select, self::FocusSearch, self::Compose, self::Help, self::Undo => false,
        };
    }

    /** @return list<array{keys: list<string>, scopes: list<string>, label: string, action: string, character: bool, editors: bool}> */
    public static function forStore(): array
    {
        return array_map(fn (self $shortcut): array => [
            'keys' => $shortcut->keys(),
            'scopes' => array_map(fn (ShortcutScope $scope): string => $scope->value, $shortcut->scopes()),
            'label' => $shortcut->label(),
            'action' => $shortcut->action(),
            'character' => $shortcut->isCharacterKey(),
            'editors' => $shortcut->firesInEditors(),
        ], self::cases());
    }
}
