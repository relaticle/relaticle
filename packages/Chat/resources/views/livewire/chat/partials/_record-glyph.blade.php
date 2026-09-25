{{-- The entity glyph for the Alpine scope var `action`, drawn from the same
     path table the chips and block headers use. $class sizes and colours it. --}}
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="{{ $class }}" aria-hidden="true">
    <path stroke-linecap="round" stroke-linejoin="round" :d="window.ChatModules.recordChipIcon(action.entity_type)"></path>
</svg>
