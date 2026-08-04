<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Analytics\AnalyticsEventDispatcher;
use App\Services\Analytics\AnalyticsEventName;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AnalyticsEventController extends Controller
{
    public function __invoke(Request $request, AnalyticsEventDispatcher $dispatcher)
    {
        $envelope = Validator::make($request->all(), [
            'event_name' => ['required', 'string', Rule::enum(AnalyticsEventName::class)],
            'event_uuid' => ['required'],
            'context' => ['required'],
            'attribution' => ['required'],
            'page_path' => ['required'],
            'placement' => ['required'],
        ])->validate();

        $eventName = AnalyticsEventName::from($envelope['event_name']);
        $dispatcher->dispatch($eventName, $envelope);

        return response()->noContent();
    }
}
