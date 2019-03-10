<?php

namespace App\Http\Controllers;

use Illuminate\Routing\Controller;
use App\Repositories\TorbRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 
 */
class IndexController extends Controller
{
    /**
     * TorbRepository
     */
    protected $torbRepository;

    /**
     *
     * @param  TorbRepository  $torbRepository
     * @return void
     */
    public function __construct(TorbRepository $torbRepository)
    {
        $this->torbRepository = $torbRepository;
    }

    private function res_error(string $error = 'unknown', int $status = 500)
    {
        return response()->json(['error' => $error], $status);
    }

    private function validate_rank($rank)
    {
        return (int) DB::select('SELECT COUNT(*) AS `CNT` FROM sheets WHERE `rank` = ?', [$rank])[0]->CNT ?? 0;
    }

    private function loginRequired()
    {
        $user = $this->torbRepository->getLoginUser();
        if (!$user) {
            return $this->res_error('login_required', 401);
        }
        return true;
    }
    private function adminLoginRequired()
    {
        $administrator = $this->torbRepository->getLoginAdministrator();
        if (!$administrator) {
            return $this->res_error('admin_login_required', 401);
        }
        return true;
    }

    public function index()
    {
        $user = $this->torbRepository->getLoginUser();

        $events = array_map(function (array $event) {
            return $this->torbRepository->sanitize_event($event);
        }, $this->torbRepository->get_events());

        return view('index', [
            'events' => $events,
            'user'   => $user,
        ]);
    }

    /**
     */
    public function initialize()
    {
        exec('./db/init.sh');
        return response('', 204);
    }

    /**
     * @param Request $request
     */
    public function login(Request $request)
    {
        $login_name = $request->input('login_name');
        $password   = $request->input('password');
    
        $user = DB::select('SELECT * FROM users WHERE login_name = ?', [$login_name]);
        $pass_hash = DB::select('SELECT SHA2(?, 256) AS `hash`', [$password])[0]->hash;
    
        if (!$user || $pass_hash != $user[0]->pass_hash) {
            return $this->res_error('authentication_failed', 401);
        }
    
        session()->put('user_id', (int)$user[0]->id);
    
        $user = $this->torbRepository->getLoginUser();
    
        return response()->json($user, 200);
    }

    /**
     */
    public function logout()
    {
        $res = $this->loginRequired();
        // Log::channel('sql')->debug(var_export($res, true));

        if ($res !== true) {
            return $res;
        }

        session()->flush();

        return response('', 204);
    }

    /**
     * @param Request $request
     */
    public function apiUsers(Request $request)
    {
        $nickname   = $request->input('nickname');
        $login_name = $request->input('login_name');
        $password   = $request->input('password');
    
        $user_id = null;
        
        $duplicated = DB::select('SELECT * FROM users WHERE login_name = ?', [$login_name]);
        if ($duplicated) {
            return $this->res_error('duplicated', 409);
        }

        DB::beginTransaction();
        try {
            DB::insert('INSERT INTO users (login_name, pass_hash, nickname) VALUES (?, SHA2(?, 256), ?)', [$login_name, $password, $nickname]);
            $user_id = DB::select('SELECT last_insert_id() as user_id')[0]->user_id;
            DB::commit();
        } catch (\Throwable $throwable) {
            DB::rollBack();
            return $this->res_error();
        }
    
        return response()->json([
            'id'       => (int)$user_id,
            'nickname' => $nickname,
        ], 201);
    }
    /**
     * @param Request $request
     * @param int    $id
     */
    public function apiUsersById(int $id, Request $request)
    {
        $res = $this->loginRequired();
        if ($res !== true) {
            return $res;
        }

        $user = DB::select('SELECT id, nickname FROM users WHERE id = ?', [$id])[0];
        if (!$user || $user->id !== $this->torbRepository->getLoginUser()->id) {
            return $this->res_error('forbidden', 403);
        }
    
        $recent_reservations = function () use ($user) {
            $recent_reservations = [];
    
            $rows = DB::select('SELECT r.*, s.rank AS sheet_rank, s.num AS sheet_num FROM reservations r INNER JOIN sheets s ON s.id = r.sheet_id WHERE r.user_id = ? ORDER BY IFNULL(r.canceled_at, r.reserved_at) DESC LIMIT 5', [$user->id]);

            foreach ($rows as $row) {
                $event = $this->torbRepository->get_event($row->event_id);
                $price = $event['sheets'][$row->sheet_rank]['price'];
                unset($event['sheets']);
                unset($event['total']);
                unset($event['remains']);
    
                $reservation = [
                    'id' => $row->id,
                    'event' => $event,
                    'sheet_rank' => $row->sheet_rank,
                    'sheet_num' => $row->sheet_num,
                    'price' => $price,
                    'reserved_at' => (new \DateTime("{$row->reserved_at}", new \DateTimeZone('UTC')))->getTimestamp(),
                ];
    
                if ($row->canceled_at) {
                    $reservation['canceled_at'] = (new \DateTime("{$row->canceled_at}", new \DateTimeZone('UTC')))->getTimestamp();
                }
    
                array_push($recent_reservations, $reservation);
            }
    
            return $recent_reservations;
        };
    
        $retUser = [];
        $retUser['id'] = $user->id;
        $retUser['nickname'] = $user->nickname;

        $retUser['recent_reservations'] = $recent_reservations($this);
        $retUser['total_price'] = (int) DB::select('SELECT IFNULL(SUM(e.price + s.price), 0) AS `total_price` FROM reservations r INNER JOIN sheets s ON s.id = r.sheet_id INNER JOIN events e ON e.id = r.event_id WHERE r.user_id = ? AND r.canceled_at IS NULL', [$user->id])[0]->total_price;
    
        $recent_events = function () use ($user) {
            $recent_events = [];
    
            $rows = DB::select('SELECT event_id FROM reservations WHERE user_id = ? GROUP BY event_id ORDER BY MAX(IFNULL(canceled_at, reserved_at)) DESC LIMIT 5', [$user->id]);
            foreach ($rows as $row) {
                $event = $this->torbRepository->get_event($row->event_id);
                foreach (array_keys($event['sheets']) as $rank) {
                    unset($event['sheets'][$rank]['detail']);
                }
                array_push($recent_events, $event);
            }
    
            return $recent_events;
        };
    
        $retUser['recent_events'] = $recent_events($this);

        // Log::channel('sql')->debug(var_export($retUser, true));

        
        return response()->json($retUser, 200);
    }

    /**
     */
    public function apiGetEvents()
    {
        $events = array_map(function (array $event) {
            return $this->torbRepository->sanitize_event($event);
        }, $this->torbRepository->get_events());
    
        return response()->json($events, 200);
    }

    /**
     * @param Request $request
     * @param int    $id
     */
    public function apiGetEventsById(int $id, Request $request)
    {
        $event_id = $id;
        $user = $this->torbRepository->getLoginUser();

        if ($user) {
            $event = $this->torbRepository->get_event($event_id, $user->id);
        } else {
            $event = $this->torbRepository->get_event($event_id);
        }
    
        if (empty($event) || !$event['public']) {
            return $this->res_error('not_found', 404);
        }
    
        $event = $this->torbRepository->sanitize_event($event);
    
        return response()->json($event, 200);
    }

    /**
     * @param Request $request
     * @param int    $id
     */
    public function apiEventsReserveById(int $id, Request $request)
    {
        $res = $this->loginRequired();

        if ($res !== true) {
            return $res;
        }

        $event_id = $id;
        $rank = $request->input('sheet_rank');
    
        $user = $this->torbRepository->getLoginUser();
        $event = $this->torbRepository->get_event($event_id, $user->id);
    
        if (empty($event) || !$event['public']) {
            return $this->res_error('invalid_event', 404);
        }
    
        if (!$this->validate_rank($rank)) {
            return $this->res_error('invalid_rank', 400);
        }
    
        $sheet = null;
        $reservation_id = null;
        while (true) {
            $sheet = DB::select('SELECT * FROM sheets WHERE id NOT IN (SELECT sheet_id FROM reservations WHERE event_id = ? AND canceled_at IS NULL FOR UPDATE) AND `rank` = ? ORDER BY RAND() LIMIT 1', [$event['id'], $rank]);
            if (!$sheet) {
                return $this->res_error('sold_out', 409);
            }
    
            DB::beginTransaction();
            try {
                DB::insert('INSERT INTO reservations (event_id, sheet_id, user_id, reserved_at) VALUES (?, ?, ?, ?)', [$event['id'], $sheet[0]->id, $user->id, (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u')]);
                $result = DB::select('SELECT last_insert_id() AS `id`')[0];
                $reservation_id = $result->id;
    
                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                continue;
            }
    
            break;
        }

        return response()->json([
            'id' => $reservation_id,
            'sheet_rank' => $rank,
            'sheet_num' => $sheet[0]->num,
        ], 202);
    }

    /**
     * @param int    $id
     * @param string    $ranks
     * @param int    $num
     */
    public function deletEventByIdRankNum(int $id, string $ranks, int $num)
    {
        $res = $this->loginRequired();
        if ($res !== true) {
            return $res;
        }

        $event_id = $id;
        $rank     = $ranks;
        $num      = $num;
    
        $user = $this->torbRepository->getLoginUser();
        $event = $this->torbRepository->get_event($event_id, $user->id);
    
        if (empty($event) || !$event['public']) {
            return $this->res_error('invalid_event', 404);
        }
    
        if (!$this->validate_rank($rank)) {
            return $this->res_error('invalid_rank', 404);
        }
    
        $sheet = DB::select('SELECT * FROM sheets WHERE `rank` = ? AND num = ?', [$rank, $num]);
        if (!$sheet) {
            return $this->res_error('invalid_sheet', 404);
        }

        $reservation = DB::select('SELECT * FROM reservations WHERE event_id = ? AND sheet_id = ? AND canceled_at IS NULL GROUP BY event_id HAVING reserved_at = MIN(reserved_at) FOR UPDATE', [$event['id'], $sheet[0]->id]);
        if (!$reservation) {
            return $this->res_error('not_reserved', 400);
        }

        if ($reservation[0]->user_id != $user->id) {
            return $this->res_error('not_permitted', 403);
        }

        DB::beginTransaction();
        try {
            DB::update('UPDATE reservations SET canceled_at = ? WHERE id = ?', [(new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'), $reservation[0]->id]);
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
    
            return $this->res_error();
        }
    
        return response('', 204);
    }

    /**
     */
    public function admin(Request $request)
    {
        $administrator = $this->torbRepository->getLoginAdministrator();

        $events = $this->torbRepository->get_events(function ($event) { return $event; });

        return view('admin', [
            'events' => $events,
            'administrator' => $administrator,
        ]);
    }

    /**
     * @param Request $request
     */
    public function adminLogin(Request $request)
    {
        $login_name = $request->input('login_name');
        $password   = $request->input('password');
    
        $administrator = DB::select('SELECT * FROM administrators WHERE login_name = ?', [$login_name]);
        $pass_hash = DB::select('SELECT SHA2(?, 256) AS `hash`', [$password])[0]->hash;
    
        if (!$administrator || $pass_hash != $administrator[0]->pass_hash) {
            return $this->res_error('authentication_failed', 401);
        }
        
        session()->put('administrator_id', (int)$administrator[0]->id);
        
        return response()->json($administrator[0], 200);
    }

    /**
     */
    public function adminLogout()
    {
        $res = $this->adminLoginRequired();
        if ($res !== true) {
            return $res;
        }

        session()->flush();

        return response('', 204);
    }

    /**
     */
    public function adminGetEvents()
    {
        $res = $this->adminLoginRequired();
        if ($res !== true) {
            return $res;
        }

        $events = $this->torbRepository->get_events(function ($event) { return $event; });
    
        return response()->json($events, 200);
    }

    /**
     * @param Request $request
     */
    public function adminCreateEvents(Request $request)
    {
        $res = $this->adminLoginRequired();
        if ($res !== true) {
            return $res;
        }

        $title  = $request->input('title');
        $public = $request->input('public') ? 1 : 0;
        $price  = $request->input('price');
    
        $event_id = null;
    
        DB::beginTransaction();
        try {
            DB::insert('INSERT INTO events (title, public_fg, closed_fg, price) VALUES (?, ?, 0, ?)', [$title, $public, $price]);
            $result = DB::select('SELECT last_insert_id() AS `id`');
            $event_id = $result[0]->id;
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
        }
    
        $event = $this->torbRepository->get_event($event_id);
    
        return response()->json($event, 200);
    }

    /**
     * @param int    $id
     */
    public function adminGetEventsById(int $id)
    {
        $res = $this->adminLoginRequired();
        if ($res !== true) {
            return $res;
        }

        $event_id = $id;

        $event = $this->torbRepository->get_event($event_id);
        if (empty($event)) {
            return $this->res_error('not_found', 404);
        }
    
        return response()->json($event, 200);
    }

    /**
     * @param Request $request
     * @param int    $id
     */
    public function adminEditEventsById(int $id, Request $request)
    {
        $res = $this->adminLoginRequired();
        if ($res !== true) {
            return $res;
        }

        $event_id = $id;
        $public = $request->input('public') ? 1 : 0;
        $closed = $request->input('closed') ? 1 : 0;
    
        if ($closed) {
            $public = 0;
        }
    
        $event = $this->torbRepository->get_event($event_id);
        if (empty($event)) {
            return $this->res_error('not_found', 404);
        }
    
        if ($event['closed']) {
            return $this->res_error('cannot_edit_closed_event', 400);
        } elseif ($event['public'] && $closed) {
            return $this->res_error('cannot_close_public_event', 400);
        }
    
        DB::beginTransaction();
        try {
            DB::update('UPDATE events SET public_fg = ?, closed_fg = ? WHERE id = ?', [$public, $closed, $event['id']]);
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
        }
        $event = $this->torbRepository->get_event($event_id);
    
        return response()->json($event, 200);
    }

    /**
     * @param int    $id
     */
    public function adminGetSalesById(int $id)
    {
        $event_id = $id;
        $event = $this->torbRepository->get_event($event_id);
    
        $reports = [];
    
        $reservations = DB::select('SELECT r.*, s.rank AS sheet_rank, s.num AS sheet_num, s.price AS sheet_price, e.price AS event_price FROM reservations r INNER JOIN sheets s ON s.id = r.sheet_id INNER JOIN events e ON e.id = r.event_id WHERE r.event_id = ? ORDER BY reserved_at ASC FOR UPDATE', [$event['id']]);
        foreach ($reservations as $reservation) {
            $report = [
                'reservation_id' => $reservation->id,
                'event_id' => $reservation->event_id,
                'rank' => $reservation->sheet_rank,
                'num' => $reservation->sheet_num,
                'user_id' => $reservation->user_id,
                'sold_at' => (new \DateTime("{$reservation->reserved_at}", new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u').'Z',
                'canceled_at' => $reservation->canceled_at ? (new \DateTime("{$reservation->canceled_at}", new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u').'Z' : '',
                'price' => $reservation->event_price + $reservation->sheet_price,
            ];
    
            array_push($reports, $report);
        }
    
        return $this->render_report_csv($reports);
    }

    /**
     */
    public function adminGetSales()
    {
        $res = $this->adminLoginRequired();
        if ($res !== true) {
            return $res;
        }

        $reports = [];
        $reservations = DB::select('SELECT r.*, s.rank AS sheet_rank, s.num AS sheet_num, s.price AS sheet_price, e.id AS event_id, e.price AS event_price FROM reservations r INNER JOIN sheets s ON s.id = r.sheet_id INNER JOIN events e ON e.id = r.event_id ORDER BY reserved_at ASC FOR UPDATE');
        foreach ($reservations as $reservation) {
            $report = [
                'reservation_id' => $reservation->id,
                'event_id' => $reservation->event_id,
                'rank' => $reservation->sheet_rank,
                'num' => $reservation->sheet_num,
                'user_id' => $reservation->user_id,
                'sold_at' => (new \DateTime("{$reservation->reserved_at}", new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u').'Z',
                'canceled_at' => $reservation->canceled_at ? (new \DateTime("{$reservation->canceled_at}", new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u').'Z' : '',
                'price' => $reservation->event_price + $reservation->sheet_price,
            ];
    
            array_push($reports, $report);
        }
        return $this->render_report_csv($reports);
    }


    private function render_report_csv(array $reports)
    {
        usort($reports, function ($a, $b) { return $a['sold_at'] > $b['sold_at']; });

        $keys = ['reservation_id', 'event_id', 'rank', 'num', 'price', 'user_id', 'sold_at', 'canceled_at'];
        $body = implode(',', $keys);
        $body .= "\n";
        foreach ($reports as $report) {
            $data = [];
            foreach ($keys as $key) {
                $data[] = $report[$key];
            }
            $body .= implode(',', $data);
            $body .= "\n";
        }

        $tmpfname = tempnam("/tmp", "FOO");
        $handle = fopen($tmpfname, "w");
        fwrite($handle, $body);

        return response()
            ->download($tmpfname, 'report.csv', [
                ['Content-Type', 'text/csv; charset=UTF-8'],
                ['Content-Disposition', 'attachment; filename="report.csv"']
            ]);
    }
}
