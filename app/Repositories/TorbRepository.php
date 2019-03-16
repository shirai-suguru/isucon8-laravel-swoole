<?php

namespace App\Repositories;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Swoole\Coroutine\Channel;

class TorbRepository
{
    public function sanitize_event(array $event): array
    {
        unset($event['price']);
        unset($event['public']);
        unset($event['closed']);
    
        return $event;
    }

    public function get_events(?callable $where = null): array
    {
        if (null === $where) {
            $where = function ($event) {
                return $event->public_fg;
            };
        }
        // $ret = DB::select('SELECT * FROM events ORDER BY id ASC');
        // Log::channel('sql')->debug(var_export($ret, true));

        $events = [];
        $event_ids = array_map(function ($event) {
            return $event->id;
        }, array_filter(DB::select('SELECT * FROM events ORDER BY id ASC'), $where));
    
        $channel = new Channel;

        foreach ($event_ids as $event_id) {
            go(function () use ($event_id, $channel) {
                $event = $this->get_event($event_id);
        
                foreach (array_keys($event['sheets']) as $rank) {
                    unset($event['sheets'][$rank]['detail']);
                }
        
                $channel->push($event);
                // array_push($events, $event);
            });
        }
        foreach ($event_ids as $event_id) {
            $events[] = $channel->pop();
        }
        foreach ($events as $key => $value) {
            $id[$key] = $value['id'];
        }
        array_multisort($id, SORT_ASC, $events);

        return $events;
    }

    public function get_event(int $event_id, ?int $login_user_id = null): array
    {
        DB::statement('SET SESSION sql_mode="STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION"');

        $retEvents = [];
        $event = DB::select('SELECT * FROM events WHERE id = ?', [$event_id])[0] ?? null;

        // Log::channel('sql')->debug(var_export($event, true));
        // Log::channel('sql')->debug(var_export($event->id, true));

        if (!$event) {
            return [];
        }
    
        $retEvents['id'] = (int) $event->id;
        $retEvents['title'] = $event->title;
        $retEvents['price'] = $event->price;
    
        // zero fill
        $retEvents['total'] = 0;
        $retEvents['remains'] = 0;
    
        foreach (['S', 'A', 'B', 'C'] as $rank) {
            $retEvents['sheets'][$rank]['total'] = 0;
            $retEvents['sheets'][$rank]['remains'] = 0;
        }
    
        $sheets = DB::select('SELECT * FROM sheets ORDER BY `rank`, num');

        foreach ($sheets as $sheet) {
            $retSheets = [];
            $retEvents['sheets'][$sheet->rank]['price'] = $retEvents['sheet'][$sheet->rank]['price'] ?? $event->price + $sheet->price;
    
            ++$retEvents['total'];
            ++$retEvents['sheets'][$sheet->rank]['total'];
    
            $reservation =  DB::select('SELECT * FROM reservations WHERE event_id = ? AND sheet_id = ? AND canceled_at IS NULL GROUP BY event_id, sheet_id HAVING reserved_at = MIN(reserved_at)', [$retEvents['id'], $sheet->id])[0] ?? null;
            // Log::channel('sql')->debug(var_export($reservation, true));

            if ($reservation) {
                $retSheets['mine'] = $login_user_id && $reservation->user_id == $login_user_id;
                $retSheets['reserved'] = true;
                $retSheets['reserved_at'] = (new \DateTime("{$reservation->reserved_at}", new \DateTimeZone('UTC')))->getTimestamp();
            } else {
                ++$retEvents['remains'];
                ++$retEvents['sheets'][$sheet->rank]['remains'];
            }

            $retSheets['num'] = $sheet->num;
            $rank = $sheet->rank;
            unset($retSheets['id']);
            unset($retSheets['price']);
            unset($retSheets['rank']);
    
            if (false === isset($retEvents['sheets'][$rank]['detail'])) {
                $retEvents['sheets'][$rank]['detail'] = [];
            }
            array_push($retEvents['sheets'][$rank]['detail'], $retSheets);
        }
    
        $retEvents['public'] = $event->public_fg ? true : false;
        $retEvents['closed'] = $event->closed_fg ? true : false;
    
        unset($retEvents['public_fg']);
        unset($retEvents['closed_fg']);

        // Log::channel('sql')->debug(var_export($retEvents, true));

        return $retEvents;
    }

    public function getLoginUser()
    {
        $user_id = session()->get('user_id');
        if (null === $user_id) {
            return false;
        }

        $user = DB::select('SELECT id, nickname FROM users WHERE id = ?', [$user_id])[0] ?? null;
        return $user;
    }

    public function getLoginAdministrator()
    {
        $administrator_id = session()->get('administrator_id');
        if (null === $administrator_id) {
            return false;
        }

        $administrator = DB::select('SELECT id, nickname FROM administrators WHERE id = ?', [$administrator_id])[0] ?? null;
        return $administrator;
    }

}
