<?php

return [
    /** Chatbot display name */
    'eli_name' => 'Eli',
    /** Official expansion (initialism): ELI — Embedded Local Intelligence */
    'eli_meaning' => 'Embedded Local Intelligence',
    /** Full brand line for headers (keep :eli and :meaning in sync with keys above). */
    'eli_brand_line' => ':eli — :meaning',

    'module_title' => 'Eli — Embedded Local Intelligence',
    'menu' => 'Eli',
    'menu_chat' => 'Chat',
    'permission_use' => 'Use Eli (Embedded Local Intelligence)',
    'install_title' => 'Install Eli — Embedded Local Intelligence',
    'install_heading' => 'Install :name',
    'install_body' => 'Runs module migrations and enables Eli — Embedded Local Intelligence: your assistant grounded in this TeamPOS business data (not generic web answers). Ensure OPENAI_API_KEY is set in your .env file.',
    'install_button' => 'Install now',
    'install_success' => 'Eli — Embedded Local Intelligence is installed.',
    'chat_heading' => 'Eli',
    'chat_intro' => 'Ask about your sales, stock, purchases, reports, and day‑to‑day operations—I stay focused on your TeamPOS business (including your industry context from Eli settings). I save your conversation so you can pick up anytime.',
    'chat_saved_hint' => 'Your conversation is saved automatically.',
    'chat_welcome_eli' => 'Hi — I’m Eli (*Embedded Local Intelligence*). Ask me about sales trends, stock gaps, top products, pricing ideas, or growth — I’m grounded in your TeamPOS data.',
    'placeholder' => 'e.g. What should I focus on this month based on recent sales?',
    'send' => 'Send',
    'clear' => 'Start new chat',
    'thinking' => 'Thinking…',
    'error_no_api_key' => 'OpenAI is not configured. Add OPENAI_API_KEY to your .env file and run php artisan config:clear.',
    'error_generic' => 'Something went wrong talking to Eli. Try again or check the logs.',
    'business_snapshot' => 'Eli · data context',
    'context_hide' => 'Hide context',
    'context_show' => 'Show context',
    'welcome_back' => 'Welcome back, :name',

    'menu_settings' => 'Eli settings',
    'settings_title' => 'Eli — Embedded Local Intelligence · settings',
    'settings_heading' => 'Eli · business context',
    'settings_intro' => 'Industry and notes below are injected into every Eli chat so answers match how you operate (read-only context for the model).',
    'field_industry' => 'Business industry / vertical',
    'field_industry_placeholder' => 'e.g. Pharmacy retail, Quick-service restaurant, Electronics wholesale',
    'field_industry_help' => 'Short label Eli should treat as authoritative for how you operate.',
    'field_context_notes' => 'Additional context',
    'field_context_notes_placeholder' => 'Goals, constraints, terminology, brands you carry, regions you serve, things Eli should never assume…',
    'field_context_notes_help' => 'Optional longer notes (max 12,000 characters). Applied together with industry for Eli’s context.',
    'settings_save' => 'Save context',
    'settings_saved' => 'Eli context saved.',
    'settings_error_no_preferences_table' => 'Preferences table is missing. Run module migrations from the Eli — Embedded Local Intelligence install.',
    'back_to_chat' => 'Back to chat',
    'chat_settings_link' => 'Edit Eli context',

    /** Short name (e.g. message attribution). */
    'floating_title' => 'Eli',
    /** FAB tooltip — keep short. */
    'floating_toggle' => 'Ask Eli',
    /** Accessible description for the FAB (full brand + action). */
    'floating_aria_toggle' => 'Ask Eli — Embedded Local Intelligence chat',
    /** Dialog landmark — opened panel (no “Open” verb). */
    'floating_panel_aria' => 'Eli — Embedded Local Intelligence chat',

    /** Injected into tool-based chat preamble (model-facing). */
    'preamble_eli_identity' => 'Assistant identity: Eli — Embedded Local Intelligence (stay grounded in this business’s TeamPOS data and permitted locations; prioritize merchant operations and Owner‑provided Industry/context — politely steer unrelated chatter back on-topic).',
    'floating_close' => 'Close',
    'floating_placeholder' => 'Sales, stock, purchases, reports, margins…',
    'floating_you' => 'You',
    'floating_ai_label' => 'Eli',
    'floating_loading_history' => 'Loading your conversation…',
    'floating_load_older_conversations' => 'Load older messages',
    'floating_loading_older' => 'Loading older messages…',

    /** Inline teaser when user is on a TeamPOS report page (floating Eli). */
    'report_teaser_title' => 'Eli',
    'report_teaser_body' => 'You’re viewing :report. I can help you interpret figures, spot trends, or relate this screen to your wider business data.',
    'report_teaser_open_eli' => 'Ask Eli',
    'report_teaser_dismiss' => 'Dismiss',
];
