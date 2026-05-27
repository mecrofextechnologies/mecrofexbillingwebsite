<?php
/**
 * creator_ticket_settings.php
 * ─────────────────────────────────────────────────────────────
 * Dual-mode include for Keychele setting checks.
 *
 * MODE A — ticket page (single event)
 *   Include AFTER $event is fetched. Exposes:
 *     $cts_show_remaining  bool
 *     $cts_show_views      bool
 *     $cts_visibility      string  'public'|'private'
 *
 * MODE B — homepage / any loop over multiple events
 *   Include ONCE anywhere before the loop. Use helper functions:
 *     cts_show_views($ev)      → bool
 *     cts_show_remaining($ev)  → bool
 *     cts_visibility($ev)      → string
 *
 * Both modes read from the `tickets` row array. The columns
 * show_views, show_remaining, visibility must be SELECTed in
 * your query. If a column is absent (old rows), defaults to ON.
 * ─────────────────────────────────────────────────────────────
 */

/* ── Helper functions — safe to redeclare check ── */

if (!function_exists('cts_show_views')) {
    /**
     * Should the view count badge be shown for this event row?
     * @param  array $ev  A row from the tickets table.
     * @return bool
     */
    function cts_show_views(array $ev): bool {
        // Column missing from old rows → default ON
        if (!array_key_exists('show_views', $ev)) return true;
        return (int)$ev['show_views'] === 1;
    }
}

if (!function_exists('cts_show_remaining')) {
    /**
     * Should the "X remaining" count be shown for this event row?
     * @param  array $ev  A row from the tickets table.
     * @return bool
     */
    function cts_show_remaining(array $ev): bool {
        if (!array_key_exists('show_remaining', $ev)) return true;
        return (int)$ev['show_remaining'] === 1;
    }
}

if (!function_exists('cts_visibility')) {
    /**
     * Returns the visibility setting for this event row.
     * @param  array $ev  A row from the tickets table.
     * @return string  'public' | 'private'
     */
    function cts_visibility(array $ev): string {
        return in_array($ev['visibility'] ?? '', ['public', 'private'], true)
            ? $ev['visibility']
            : 'public';
    }
}

/* ── MODE A: single-event variables (ticket page) ── */
if (isset($event) && is_array($event)) {
    $cts_show_views     = cts_show_views($event);
    $cts_show_remaining = cts_show_remaining($event);
    $cts_visibility     = cts_visibility($event);
} else {
    /* Not on ticket page — safe fallback */
    $cts_show_views     = true;
    $cts_show_remaining = true;
    $cts_visibility     = 'public';
}