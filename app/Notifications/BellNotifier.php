<?php

namespace App\Notifications;

use App\Models\User;
use Filament\Notifications\Events\DatabaseNotificationsSent;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;

/**
 * Satu tempat mengirim notifikasi ke lonceng panel — dipakai semua listener.
 *
 * notifyNow(), BUKAN sendToDatabase(): API fluent Filament mengirim lewat notify()
 * yang ANTRE, jadi tanpa worker loncengnya tak pernah terisi. notifyNow menulis
 * barisnya SEKARANG (lonceng langsung terisi); DatabaseNotificationsSent mendorong
 * realtime lewat Reverb. Tanpa Reverb/worker, polling lonceng yang memunculkannya.
 */
class BellNotifier
{
    /**
     * @param  (callable(Builder<User>): Builder<User>)|null  $scope  Menyaring penerima (mis. hanya owner). Null = semua staf aktif.
     */
    public static function send(Notification $notification, ?callable $scope = null): void
    {
        User::query()
            ->where('is_active', true)
            ->when($scope !== null, $scope)
            ->each(function (User $user) use ($notification): void {
                $user->notifyNow($notification->toDatabase());
                event(new DatabaseNotificationsSent($user));
            });

        // Telegram ke ops DITUNDA sampai setelah respons dikirim: panggilannya ke
        // internet publik (api.telegram.org) bisa lambat/hang (timeout+retry
        // sampai ~30s), dan tak boleh menahan permintaan PELANGGAN yang memicunya
        // (upload bukti transfer, stop Open Play). Lonceng panel tetap instan
        // (notifyNow di atas); hanya leg Telegram yang ditunda. defer() jalan
        // pasca-respons tanpa butuh worker.
        $title = (string) $notification->getTitle();
        $body = (string) $notification->getBody();

        defer(fn () => TelegramNotifier::send($title, $body));
    }
}
