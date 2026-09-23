<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Conformance\A2ui;

use Webconsulting\AgentNexus\Tests\Conformance\ConformanceTestCase;

/**
 * Conformance tests for A2UI. The shared validator binds the envelope's
 * `catalog.json` placeholder to the basic catalogue as the specification
 * instructs; {@see A2uiSchemas} names the schemas.
 */
abstract class A2uiConformanceTestCase extends ConformanceTestCase {}
