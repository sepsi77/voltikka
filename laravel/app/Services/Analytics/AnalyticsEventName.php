<?php

namespace App\Services\Analytics;

enum AnalyticsEventName: string
{
    case ContractOrderClick = 'contract_order_click';
    case PageFeedback = 'page_feedback';
}
