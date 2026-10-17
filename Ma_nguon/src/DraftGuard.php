<?php
declare(strict_types=1);

function canComplete(array $session, int $eventId, int $now, int $ttl): bool {
    if (!isset($session['draft'])) {
        return false;
    }
    $draft = $session['draft'];
    if (!isset($draft['data'], $draft['created_at'])) {
        return false;
    }
    if ($ttl <= 0) {
        return false;
    }
    if ($draft['created_at'] > $now) {
        return false;
    }
    if ($now - $draft['created_at'] >= $ttl) {
        return false;
    }
    if ((int)$draft['data']['event_id'] !== $eventId) {
        return false;
    }
    return true;
}
