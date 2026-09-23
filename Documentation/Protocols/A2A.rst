:navigation-title: A2A

..  include:: /Includes.rst.txt
..  _protocol-a2a:

====================
A2A — agent to agent
====================

Two agents can only work together if one can find out what the other does.
A2A makes that concrete: an agent publishes an **Agent Card** that says who it
is, where to reach it and which skills it has; another agent reads the card and
sends it a message. The message starts a **task** with a defined lifecycle, and
the result comes back as named **artifacts**.

Agent Nexus 4.0 implements A2A **1.0** (specification 1.0.1, wire version
``1.0``) over both standard HTTP bindings, JSON-RPC 2.0 and HTTP+JSON. A client
written for A2A 0.3 is still answered in 0.3 on the JSON-RPC endpoint. See
:ref:`spec-versions` for what changed since 3.1.

How a request flows
===================

#.  **Discovery.** The client reads the Agent Card at
    ``/.well-known/agent-card.json``. Its ``supportedInterfaces`` list the
    endpoints in order of preference: JSON-RPC for 1.0, HTTP+JSON for 1.0,
    and JSON-RPC for 0.3.
#.  **Message.** The client sends ``SendMessage`` (wait for the result) or
    ``SendStreamingMessage`` (follow the task). The server creates the task,
    with an id and a context id of its own, and saves it as
    ``TASK_STATE_SUBMITTED``.
#.  **Lifecycle.** The agent routes the message to a skill and reports
    ``TASK_STATE_WORKING``. A skill that needs more detail stops at
    ``TASK_STATE_INPUT_REQUIRED`` and asks; the stream closes there.
#.  **Answer.** The client answers with a new message that carries the task's
    ``taskId``. The same task continues.
#.  **Artifacts.** The result streams as ``artifactUpdate`` events — the first
    opens the artifact, later ones append, the last says so — and the task
    ends as ``TASK_STATE_COMPLETED``.

Every state change is saved before it is announced, so ``GetTask``,
``ListTasks``, ``CancelTask`` and ``SubscribeToTask`` read the same task the
stream describes.

Endpoints
=========

Paths below ``/api/agent-nexus`` follow the ``apiBasePath`` extension
setting. The Agent Card at the well-known address is published while
``publishWellKnown`` is on.

..  list-table::
    :header-rows: 1
    :widths: 12 44 44

    *   -   Method
        -   Path
        -   Purpose
    *   -   GET
        -   ``/.well-known/agent-card.json``
        -   The Agent Card: identity, interfaces, capabilities, skills.
    *   -   GET
        -   ``/api/agent-nexus/a2a/agent-card.json``
        -   The same card below the API path.
    *   -   POST
        -   ``/api/agent-nexus/a2a/jsonrpc``
        -   JSON-RPC 2.0: every method, streaming ones as Server-Sent Events.
    *   -   POST
        -   ``/api/agent-nexus/a2a/rest/message:send``
        -   HTTP+JSON ``SendMessage``.
    *   -   POST
        -   ``/api/agent-nexus/a2a/rest/message:stream``
        -   HTTP+JSON ``SendStreamingMessage`` (Server-Sent Events).
    *   -   GET
        -   ``/api/agent-nexus/a2a/rest/tasks``
        -   ``ListTasks``: filters and pages as query parameters.
    *   -   GET
        -   ``/api/agent-nexus/a2a/rest/tasks/{id}``
        -   ``GetTask``; ``?historyLength=`` limits the history.
    *   -   POST
        -   ``/api/agent-nexus/a2a/rest/tasks/{id}:cancel``
        -   ``CancelTask``.
    *   -   GET, POST
        -   ``/api/agent-nexus/a2a/rest/tasks/{id}:subscribe``
        -   ``SubscribeToTask`` (Server-Sent Events). Both methods are
            accepted: the specification text says POST, the proto says GET.
    *   -   GET, POST
        -   ``/api/agent-nexus/a2a/rest/tasks/{id}/pushNotificationConfigs``
        -   Push notification settings. Not supported; answers with an error.
    *   -   GET, DELETE
        -   ``/api/agent-nexus/a2a/rest/tasks/{id}/pushNotificationConfigs/{configId}``
        -   One push notification setting. Not supported; answers with an error.
    *   -   GET
        -   ``/api/agent-nexus/a2a/rest/extendedAgentCard``
        -   The extended Agent Card. Not offered; answers with an error.

The router answers ``OPTIONS`` itself and allows every origin, so an agent's
browser client can call the endpoints directly. The backend shows the same
list on the Agent Card screen, and the "Protocol info" content element on
the website.

Methods
=======

..  list-table::
    :header-rows: 1

    *   -   A2A 1.0 (JSON-RPC)
        -   A2A 0.3 (JSON-RPC)
        -   Answer
    *   -   ``SendMessage``
        -   ``message/send``
        -   ``{"task": …}`` (0.3: the bare Task)
    *   -   ``SendStreamingMessage``
        -   ``message/stream``
        -   Server-Sent Events
    *   -   ``GetTask``
        -   ``tasks/get``
        -   The Task
    *   -   ``ListTasks``
        -   —
        -   ``{tasks, nextPageToken, pageSize, totalSize}``
    *   -   ``CancelTask``
        -   ``tasks/cancel``
        -   The Task
    *   -   ``SubscribeToTask``
        -   ``tasks/resubscribe``
        -   Server-Sent Events
    *   -   ``CreateTaskPushNotificationConfig`` and the other three push methods
        -   ``tasks/pushNotificationConfig/set`` …
        -   ``PushNotificationNotSupportedError`` (``-32003``)
    *   -   ``GetExtendedAgentCard``
        -   ``agent/getAuthenticatedExtendedCard``
        -   ``UnsupportedOperationError`` (``-32004``)

Versions
========

A client names its version in the ``A2A-Version`` header, or in an
``A2A-Version`` query parameter. Only ``Major.Minor`` counts: ``1.0.1`` is
``1.0``.

*   ``A2A-Version: 1.0`` gets the 1.0 method names and payloads, and the
    response carries ``A2A-Version: 1.0``.
*   **No header** means an A2A 0.3 client, as the specification requires.
    The JSON-RPC endpoint then speaks 0.3: ``message/send`` and its siblings,
    ``kind`` on every object, states like ``input-required``, roles ``user``
    and ``agent``, parts ``{"kind": "text", "text": …}``, ``final: true`` on
    the status update that closes a stream, and ``message/send`` answering
    with the bare Task. One translator maps 0.3 parameters in and every result
    and frame out; the task logic runs once.
*   A 1.0 method name without the header — the usual mistake — gets
    ``-32601`` with a message that says to send ``A2A-Version: 1.0``. A 0.3
    method name with the 1.0 header names the 1.0 method to call instead.
*   Any other version gets ``VersionNotSupportedError`` (``-32009``); its
    ``google.rpc.ErrorInfo`` lists the supported versions in
    ``metadata.supportedVersions``.
*   The HTTP+JSON binding speaks 1.0 only: without the header a request is a
    0.3 request and is refused with ``VersionNotSupportedError``.

Tasks
=====

Tasks live in the protocol object store (``tx_agentnexus_object``): the
payload is the Task exactly as it goes over the wire, the history column
records every state with its time, and the label is the skill's name. The
inspector reads the same rows.

*   **Ids.** Task, context, message and artifact ids are random UUIDs. A
    client may choose the context of a new task; a ``taskId`` must name an
    existing task, and a ``contextId`` sent with it must match the task's.
*   **Answering a question.** A message for a task in
    ``TASK_STATE_INPUT_REQUIRED`` continues it. A message for a finished task
    gets ``UnsupportedOperationError`` (``-32004``), as does a second message
    while the task is still working on the first.
*   **Streams.** Every event carries content: an artifact streams in chunks of
    a few words, each with a text part. A stream closes after a terminal state
    (completed, failed, cancelled, rejected) or after
    ``TASK_STATE_INPUT_REQUIRED``. A client that drops the connection does not
    stop the task: the rest of the turn still happens and is saved.
*   **returnImmediately.** ``SendMessage`` blocks until the task finishes or
    asks for input. With ``configuration.returnImmediately: true`` it returns
    the submitted task at once; a PHP request has no background worker, so the
    next ``GetTask`` or ``SubscribeToTask`` for that task does the work.
*   **historyLength.** Unset returns the whole history, ``0`` none, and ``n``
    the ``n`` newest messages.
*   **Cancelling.** ``CancelTask`` stops a task that has not finished, also
    while another request streams it. Cancelling a cancelled task again
    returns it unchanged; a completed, failed or rejected task gets
    ``TaskNotCancelableError`` (``-32002``).
*   **Subscribing.** ``SubscribeToTask`` sends the task as it is now, then its
    updates until it finishes or asks for input. A finished task gets
    ``UnsupportedOperationError``; a task that asks for input gets just the
    Task.
*   **Listing.** ``ListTasks`` returns the most recently updated tasks first,
    50 per page by default (1 to 100). Pages are keyset pages, so tasks that
    change while you page do not shift them; ``nextPageToken`` is empty on
    the last page. ``statusTimestampAfter`` is compared at second precision,
    and ``artifacts`` are included only with ``includeArtifacts=true``.
    Tasks started in the concierge widget hold what a visitor typed: they are
    listed only to a client that names their ``contextId``.

Errors
======

JSON-RPC errors are JSON-RPC error objects sent with HTTP 200, the usual
convention for JSON-RPC over HTTP. The one exception is the rate limit: HTTP
429 with a ``Retry-After`` header, so that plain HTTP tooling backs off too.
A streaming call that fails before its first frame gets a plain JSON error
instead of a stream; a failure later in a stream ends it with an error frame.

HTTP+JSON errors use the HTTP status of the specification's error table and a
``google.rpc.Status`` body under ``error``. Both bindings attach a
``google.rpc.ErrorInfo`` (domain ``a2a-protocol.org``) to every A2A error and
a ``google.rpc.BadRequest`` naming the field to every invalid parameter.

..  list-table::
    :header-rows: 1

    *   -   Error
        -   JSON-RPC
        -   HTTP
        -   When
    *   -   ``TaskNotFoundError``
        -   ``-32001``
        -   404
        -   The task id is unknown or the task was deleted.
    *   -   ``TaskNotCancelableError``
        -   ``-32002``
        -   400
        -   ``CancelTask`` for a completed, failed or rejected task.
    *   -   ``PushNotificationNotSupportedError``
        -   ``-32003``
        -   400
        -   Any push notification method, or a push config inside
            ``SendMessage``.
    *   -   ``UnsupportedOperationError``
        -   ``-32004``
        -   400
        -   A message for a finished or busy task, subscribing to a finished
            task, ``GetExtendedAgentCard``.
    *   -   ``ContentTypeNotSupportedError``
        -   ``-32005``
        -   400
        -   A part that is not text, or ``acceptedOutputModes`` without
            ``text/markdown`` or ``text/plain``.
    *   -   ``VersionNotSupportedError``
        -   ``-32009``
        -   400
        -   An ``A2A-Version`` other than ``1.0`` and ``0.3``.
    *   -   Rate limit (this installation's own)
        -   ``-32000``
        -   429
        -   More than 30 A2A calls in ten minutes from one address.

The card declares ``capabilities.extendedAgentCard: false``. For that case the
specification (section 3.3.4) asks for ``UnsupportedOperationError``, not
``ExtendedAgentCardNotConfiguredError`` (``-32007``), which is for an agent
that declares an extended card but has none configured.

What is simulated
=================

*   The three skills — summarise a page, draft an outreach email, plan an
    onboarding — return scripted artifacts. One of them asks who the email is
    for, so ``TASK_STATE_INPUT_REQUIRED`` is not theoretical.
*   A model is used only for the concierge on the website, only when its
    content element allows it (**Use a model**), the ``a2aLlmEnabled``
    extension setting and the frontend LLM guard agree, and the ``a2a.llm``
    budget of 10 model calls per address in ten minutes has room. The model
    may choose the skill (with a rationale, unless **Show the rationale** is
    off) and write the artifact; the artifact's metadata says which
    (``writtenBy``). Every other client — including the backend console —
    gets the scripted skills. Settings are read from the content element on the
    server, never from the request.
*   There are no push notifications and no extended card. The card declares
    no security schemes: this demo agent is public.

Try it
======

Read the card:

..  code-block:: bash

    curl https://example.org/.well-known/agent-card.json

Send a message and wait for the finished task:

..  code-block:: bash

    curl https://example.org/api/agent-nexus/a2a/jsonrpc \
      -H 'Content-Type: application/json' \
      -H 'A2A-Version: 1.0' \
      -d '{"jsonrpc": "2.0", "id": 1, "method": "SendMessage",
           "params": {"message": {"messageId": "m-1", "role": "ROLE_USER",
           "parts": [{"text": "Summarise the pricing page"}]}}}'

Follow a task that asks a question, then answer it with its ``taskId`` and
``contextId`` from the first frame:

..  code-block:: bash

    curl -N https://example.org/api/agent-nexus/a2a/jsonrpc \
      -H 'Content-Type: application/json' \
      -H 'A2A-Version: 1.0' \
      -d '{"jsonrpc": "2.0", "id": 2, "method": "SendStreamingMessage",
           "params": {"message": {"messageId": "m-2", "role": "ROLE_USER",
           "parts": [{"text": "Draft an outreach email about our plans"}]}}}'

    curl -N https://example.org/api/agent-nexus/a2a/jsonrpc \
      -H 'Content-Type: application/json' \
      -H 'A2A-Version: 1.0' \
      -d '{"jsonrpc": "2.0", "id": 3, "method": "SendStreamingMessage",
           "params": {"message": {"messageId": "m-3", "role": "ROLE_USER",
           "taskId": "<task id>", "contextId": "<context id>",
           "parts": [{"text": "Agencies"}]}}}'

The same over HTTP+JSON:

..  code-block:: bash

    curl https://example.org/api/agent-nexus/a2a/rest/message:send \
      -H 'Content-Type: application/a2a+json' \
      -H 'A2A-Version: 1.0' \
      -d '{"message": {"messageId": "m-4", "role": "ROLE_USER",
           "parts": [{"text": "Plan the onboarding of a new agency"}]}}'

    curl -H 'A2A-Version: 1.0' \
      'https://example.org/api/agent-nexus/a2a/rest/tasks?pageSize=10&status=TASK_STATE_COMPLETED'

As an A2A 0.3 client — no header:

..  code-block:: bash

    curl https://example.org/api/agent-nexus/a2a/jsonrpc \
      -H 'Content-Type: application/json' \
      -d '{"jsonrpc": "2.0", "id": 5, "method": "message/send",
           "params": {"message": {"kind": "message", "messageId": "m-5",
           "role": "user", "parts": [{"kind": "text", "text": "Summarise the pricing page"}]}}}'

In this installation
====================

*   **Backend, A2A > Task console.** Plays a client agent: reads the card,
    calls the public JSON-RPC endpoint from the browser in 1.0 or 0.3, lists
    every request and frame, answers the agent's question, and gets or cancels
    the task. Recent tasks link to the inspector.
*   **Backend, A2A > Agent Card.** The published card with its interfaces,
    capabilities, skills, endpoints, methods, task states and error codes,
    each read from the code that serves it.
*   **Website, concierge content element.** Sends
    ``SendStreamingMessage`` with its content element, page and URL in the
    message metadata (``metadata.agentNexus``), so its tasks are stored in the
    site's storage folder and appear as "Frontend widget" in the inspector and
    the traffic log. A chip pins a skill through ``metadata.skill``.
*   **Traffic log.** Every call is recorded with its method as the operation
    and the task id as the correlation id; streams keep every frame.
*   **Caching.** The card is sent with ``Cache-Control: public, max-age=300``
    and an ``ETag`` over its content, and ``If-None-Match`` gets a 304. The
    default TYPO3 :file:`.htaccess` removes ``ETag`` headers on Apache; the
    card is then cached by ``max-age`` alone.

..  note::

    The Agent Card is public and advertises this site to any agent that looks
    for it. Make sure the skills it lists are ones you want published.
