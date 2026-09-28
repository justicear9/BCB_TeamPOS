<?php

return [
    'name' => 'AIBusinessManager',
    'module_version' => '1.0.0',

    /*
    | OpenAI chat model. Uses AI_BUSINESS_MANAGER_MODEL, then OPENAI_MODEL, then default.
    */
    'model' => env('AI_BUSINESS_MANAGER_MODEL', env('OPENAI_MODEL', 'gpt-4o-mini')),

    'max_output_tokens' => (int) env('AI_BUSINESS_MANAGER_MAX_TOKENS', 2048),

    'max_history_messages' => (int) env('AI_BUSINESS_MANAGER_MAX_HISTORY', 16),
    'max_memory_messages' => (int) env('AI_BUSINESS_MANAGER_MAX_MEMORY_MESSAGES', 300),

    /** Product rows sampled into context (names + categories) */
    'product_sample_limit' => (int) env('AI_BUSINESS_MANAGER_PRODUCT_SAMPLE', 200),

    /** Use OpenAI tool calls + server-side SQL (read-only, scoped). */
    'enable_tools' => filter_var(env('AI_BUSINESS_MANAGER_ENABLE_TOOLS', true), FILTER_VALIDATE_BOOL),

    'max_tool_rounds' => (int) env('AI_BUSINESS_MANAGER_MAX_TOOL_ROUNDS', 14),

    /** Max span for tool date ranges (days inclusive). */
    'max_tool_date_span_days' => (int) env('AI_BUSINESS_MANAGER_MAX_TOOL_DATE_SPAN_DAYS', 800),

    /** Eli extended tools: default row caps and behaviour */
    'tool_contact_search_limit' => (int) env('AI_BUSINESS_MANAGER_TOOL_CONTACT_SEARCH_LIMIT', 25),
    'redact_contact_pii' => filter_var(env('AI_BUSINESS_MANAGER_REDACT_CONTACT_PII', false), FILTER_VALIDATE_BOOL),
    'tool_ageing_detail_limit' => (int) env('AI_BUSINESS_MANAGER_TOOL_AGEING_DETAIL_LIMIT', 60),
    'tool_product_search_limit' => (int) env('AI_BUSINESS_MANAGER_TOOL_PRODUCT_SEARCH_LIMIT', 40),
    'tool_product_metrics_match_limit' => (int) env('AI_BUSINESS_MANAGER_TOOL_PRODUCT_METRICS_MATCH_LIMIT', 15),
    'tool_product_metrics_combine_limit' => (int) env('AI_BUSINESS_MANAGER_TOOL_PRODUCT_METRICS_COMBINE_LIMIT', 8),
    'tool_transaction_line_limit' => (int) env('AI_BUSINESS_MANAGER_TOOL_TRANSACTION_LINE_LIMIT', 80),
    'tool_cashier_limit' => (int) env('AI_BUSINESS_MANAGER_TOOL_CASHIER_LIMIT', 30),
    'sales_by_cashier_requires_permission' => filter_var(env('AI_BUSINESS_MANAGER_SALES_BY_CASHIER_REQUIRES_PERMISSION', true), FILTER_VALIDATE_BOOL),
    'slow_moving_max_sell_qty' => (float) env('AI_BUSINESS_MANAGER_SLOW_MOVING_MAX_SELL_QTY', 3),
    'tool_slow_stock_limit' => (int) env('AI_BUSINESS_MANAGER_TOOL_SLOW_STOCK_LIMIT', 40),
    'tool_margin_snapshot_limit' => (int) env('AI_BUSINESS_MANAGER_TOOL_MARGIN_SNAPSHOT_LIMIT', 35),
    'reorder_cover_min_sold_for_confidence' => (float) env('AI_BUSINESS_MANAGER_REORDER_COVER_MIN_SOLD', 5),
    'tool_accounting_tb_account_limit' => (int) env('AI_BUSINESS_MANAGER_TOOL_ACCOUNTING_TB_LIMIT', 80),
    'tool_accounting_ageing_contact_limit' => (int) env('AI_BUSINESS_MANAGER_TOOL_ACCOUNTING_AGEING_LIMIT', 60),
    'tool_accounting_cf_account_limit' => (int) env('AI_BUSINESS_MANAGER_TOOL_ACCOUNTING_CF_LIMIT', 40),
];
