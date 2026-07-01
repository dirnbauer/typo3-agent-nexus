#
# LLM usage ledger — one row per LLM call from any protocol (backend modules +
# frontend plugins), so the hub can show combined and per-protocol spend and
# the frontend budget guard has something to count against.
#
CREATE TABLE tx_agentnexus_llm_usage (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    request_date int(11) unsigned DEFAULT '0' NOT NULL,
    protocol varchar(12) DEFAULT '' NOT NULL,
    source varchar(16) DEFAULT '' NOT NULL,
    be_user int(11) unsigned DEFAULT '0' NOT NULL,
    model varchar(120) DEFAULT '' NOT NULL,
    prompt_tokens int(11) unsigned DEFAULT '0' NOT NULL,
    completion_tokens int(11) unsigned DEFAULT '0' NOT NULL,
    total_tokens int(11) unsigned DEFAULT '0' NOT NULL,
    cost decimal(12,6) DEFAULT '0.000000' NOT NULL,

    PRIMARY KEY (uid),
    KEY request_date (request_date),
    KEY protocol (protocol),
    KEY source (source)
);

#
# A2UI inquiries — submissions from the frontend adaptive inquiry form.
#
CREATE TABLE tx_agentnexus_a2ui_inquiry (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    page_uid int(11) unsigned DEFAULT '0' NOT NULL,
    source_url varchar(2048) DEFAULT '' NOT NULL,
    intent text,
    surface_id varchar(120) DEFAULT '' NOT NULL,
    payload mediumtext,
    data mediumtext,

    PRIMARY KEY (uid),
    KEY page_uid (page_uid)
);

#
# AG-UI run log — one row per agent run (backend module + frontend assistant),
# the "compliance / activity" record: how many events, approvals, outcome.
#
CREATE TABLE tx_agentnexus_agui_run_log (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    request_date int(11) unsigned DEFAULT '0' NOT NULL,
    source varchar(16) DEFAULT '' NOT NULL,
    be_user int(11) unsigned DEFAULT '0' NOT NULL,
    thread_id varchar(64) DEFAULT '' NOT NULL,
    run_id varchar(64) DEFAULT '' NOT NULL,
    preset varchar(64) DEFAULT '' NOT NULL,
    event_count int(11) unsigned DEFAULT '0' NOT NULL,
    approved tinyint(1) unsigned DEFAULT '0' NOT NULL,
    outcome varchar(32) DEFAULT '' NOT NULL,

    PRIMARY KEY (uid),
    KEY request_date (request_date),
    KEY source (source)
);

#
# AG-UI leads — submissions the frontend Live Assistant captured (after the
# visitor approved the human-in-the-loop confirmation).
#
CREATE TABLE tx_agentnexus_agui_lead (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    page_uid int(11) unsigned DEFAULT '0' NOT NULL,
    source_url varchar(2048) DEFAULT '' NOT NULL,
    intent text,
    data mediumtext,

    PRIMARY KEY (uid),
    KEY page_uid (page_uid)
);

#
# A2A task log — one row per delegated task (backend console, public JSON-RPC,
# frontend Concierge): the activity / audit record.
#
CREATE TABLE tx_agentnexus_a2a_task_log (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    request_date int(11) unsigned DEFAULT '0' NOT NULL,
    source varchar(16) DEFAULT '' NOT NULL,
    be_user int(11) unsigned DEFAULT '0' NOT NULL,
    task_id varchar(64) DEFAULT '' NOT NULL,
    context_id varchar(64) DEFAULT '' NOT NULL,
    skill varchar(64) DEFAULT '' NOT NULL,
    final_state varchar(32) DEFAULT '' NOT NULL,
    event_count int(11) unsigned DEFAULT '0' NOT NULL,
    artifact_count int(11) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY request_date (request_date)
);

#
# A2A Concierge requests — what a visitor asked and the artifact returned.
#
CREATE TABLE tx_agentnexus_a2a_request (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    page_uid int(11) unsigned DEFAULT '0' NOT NULL,
    source_url varchar(2048) DEFAULT '' NOT NULL,
    skill varchar(64) DEFAULT '' NOT NULL,
    prompt text,
    answer text,
    data text,

    PRIMARY KEY (uid)
);

#
# UCP order log — one row per agent-driven checkout (backend console + frontend).
# All orders are SIMULATED; this is the activity / audit record.
#
CREATE TABLE tx_agentnexus_ucp_order_log (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    request_date int(11) unsigned DEFAULT '0' NOT NULL,
    source varchar(16) DEFAULT '' NOT NULL,
    be_user int(11) unsigned DEFAULT '0' NOT NULL,
    order_id varchar(64) DEFAULT '' NOT NULL,
    intent varchar(64) DEFAULT '' NOT NULL,
    final_state varchar(32) DEFAULT '' NOT NULL,
    item_count int(11) unsigned DEFAULT '0' NOT NULL,
    total_cents int(11) unsigned DEFAULT '0' NOT NULL,
    event_count int(11) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY request_date (request_date)
);

#
# UCP frontend orders — what a visitor's agent assembled and authorized (SIMULATED).
#
CREATE TABLE tx_agentnexus_ucp_order (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    page_uid int(11) unsigned DEFAULT '0' NOT NULL,
    source_url varchar(2048) DEFAULT '' NOT NULL,
    order_id varchar(64) DEFAULT '' NOT NULL,
    intent varchar(64) DEFAULT '' NOT NULL,
    total_cents int(11) unsigned DEFAULT '0' NOT NULL,
    cart text,
    contact text,

    PRIMARY KEY (uid)
);

#
# AP2 mandate log — one row per mint/verify in the studio + frontend.
# All mandates are sandbox-signed (demo key); this is the activity record.
#
CREATE TABLE tx_agentnexus_ap2_mandate_log (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    request_date int(11) unsigned DEFAULT '0' NOT NULL,
    source varchar(16) DEFAULT '' NOT NULL,
    be_user int(11) unsigned DEFAULT '0' NOT NULL,
    action varchar(24) DEFAULT '' NOT NULL,
    mandate_type varchar(24) DEFAULT '' NOT NULL,
    authorized tinyint(1) unsigned DEFAULT '0' NOT NULL,
    total_cents int(11) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY request_date (request_date)
);

#
# AP2 frontend authorizations — what a visitor authorized on the Trusted Surface
# (simulated). Stores the cart + the issued mandate ids.
#
CREATE TABLE tx_agentnexus_ap2_authorization (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    page_uid int(11) unsigned DEFAULT '0' NOT NULL,
    source_url varchar(2048) DEFAULT '' NOT NULL,
    intent_jti varchar(64) DEFAULT '' NOT NULL,
    cart_jti varchar(64) DEFAULT '' NOT NULL,
    authorized tinyint(1) unsigned DEFAULT '0' NOT NULL,
    total_cents int(11) unsigned DEFAULT '0' NOT NULL,
    cart text,

    PRIMARY KEY (uid)
);
