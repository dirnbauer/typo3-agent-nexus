#
# Protocol objects - one row per A2UI surface, AG-UI run, A2A task, UCP
# checkout session and AP2 mandate or receipt.
#
# This is protocol state, not only history: A2A answers GetTask/ListTasks and
# resumes paused tasks from it, UCP serves GET /checkout-sessions/{id} from it.
# `payload` is the object in its specification's own JSON shape; `history` is
# the list of states it passed through. The inspector module reads both.
#
CREATE TABLE tx_agentnexus_object (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    protocol varchar(8) DEFAULT '' NOT NULL,
    kind varchar(16) DEFAULT '' NOT NULL,
    object_id varchar(128) DEFAULT '' NOT NULL,
    context_id varchar(128) DEFAULT '' NOT NULL,
    state varchar(32) DEFAULT '' NOT NULL,
    source varchar(16) DEFAULT '' NOT NULL,
    label varchar(255) DEFAULT '' NOT NULL,
    payload mediumtext,
    history mediumtext,
    be_user int(11) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    UNIQUE KEY object (kind, object_id),
    KEY listing (kind, tstamp),
    KEY protocol_activity (protocol, tstamp),
    KEY context (context_id)
);

#
# Live traffic log - one row per protocol exchange: public API calls, widget
# requests, backend console calls and the demo agents' in-process calls.
#
# Bodies are capped and personal data is masked before a row is written (see
# TrafficRedactor). Rows older than the `trafficRetentionDays` extension
# setting are deleted by the `agentnexus:cleanup` command.
#
CREATE TABLE tx_agentnexus_traffic (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    protocol varchar(8) DEFAULT '' NOT NULL,
    channel varchar(16) DEFAULT '' NOT NULL,
    method varchar(8) DEFAULT '' NOT NULL,
    endpoint varchar(255) DEFAULT '' NOT NULL,
    operation varchar(64) DEFAULT '' NOT NULL,
    correlation_id varchar(128) DEFAULT '' NOT NULL,
    status_code smallint(5) unsigned DEFAULT '0' NOT NULL,
    is_error tinyint(1) unsigned DEFAULT '0' NOT NULL,
    is_stream tinyint(1) unsigned DEFAULT '0' NOT NULL,
    duration_ms int(11) unsigned DEFAULT '0' NOT NULL,
    event_count int(11) unsigned DEFAULT '0' NOT NULL,
    request_headers text,
    request_body mediumtext,
    response_headers text,
    response_body mediumtext,
    events mediumtext,
    error varchar(255) DEFAULT '' NOT NULL,
    be_user int(11) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY created (crdate),
    KEY protocol_created (protocol, crdate),
    KEY correlation (correlation_id)
);

#
# LLM usage ledger - one row per model call from any protocol (backend consoles
# and frontend widgets), so the overview can show spend and the frontend budget
# guard has something to count against. Streamed calls bypass nr-llm's own usage
# middleware, which makes this the only record of them.
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
# Seed keys - how `agentnexus:seed-site` stays idempotent.
#
# The command addresses every record it owns by a stable logical key (e.g.
# "root", "page:a2ui", "ce:a2ui:demo") instead of by uid, title or slug, so a
# second run updates exactly the same rows even after an editor renamed or moved
# them. Records without a key were never seeded and are never touched.
#
CREATE TABLE pages (
    tx_agentnexus_seed_key varchar(64) DEFAULT '' NOT NULL,

    KEY tx_agentnexus_seed_key (tx_agentnexus_seed_key)
);

CREATE TABLE tt_content (
    tx_agentnexus_seed_key varchar(64) DEFAULT '' NOT NULL,

    KEY tx_agentnexus_seed_key (tx_agentnexus_seed_key)
);
