<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2ui\Domain\Model;

/**
 * The kinds of value a catalogue property holds, named after the A2UI common
 * types they come from.
 */
enum PropertyType: string
{
    /** A literal string, a data binding or a function call returning a string. */
    case DynamicString = 'DynamicString';
    case DynamicNumber = 'DynamicNumber';
    case DynamicBoolean = 'DynamicBoolean';
    /** A list of strings, a data binding or a function call returning one. */
    case DynamicStringList = 'DynamicStringList';
    /** A DynamicString that must hold an ISO 8601 date, time or date-time. */
    case DateTime = 'DateTime';
    /** The id of another component of the same surface. */
    case ComponentId = 'ComponentId';
    /** A list of component ids, or a template over a list in the data model. */
    case ChildList = 'ChildList';
    /** A server event or a local function call. */
    case Action = 'Action';
    /** Validation rules: a list of {condition, message}. */
    case Checks = 'Checks';
    /** One of a fixed list of strings. */
    case Enum = 'enum';
    case String = 'string';
    case Number = 'number';
    case Integer = 'integer';
    case Boolean = 'boolean';
    /** An icon name from the catalogue, a custom SVG path or a data binding. */
    case IconName = 'IconName';
    /** Tabs: a list of {title, child}. */
    case Tabs = 'Tabs';
    /** Choice options: a list of {label, value}. */
    case Options = 'Options';
    /** Accessibility attributes: label and description (v1.0 adds live and hidden). */
    case Accessibility = 'AccessibilityAttributes';
    /** An object this agent never emits (v1.0 component metadata). */
    case Object = 'object';
    /** Any JSON value (the argument of `required`). */
    case Any = 'any';
    /** A literal of any type, a data binding or a function call (action context values). */
    case DynamicValue = 'DynamicValue';
    /** An absolute URL. */
    case Uri = 'uri';
    /** An absolute URL, a data binding or a function call returning one (v1.0 `openUrl`). */
    case DynamicUri = 'DynamicUri';
    /** At least two DynamicBooleans (the argument of `and` and `or`). */
    case BooleanList = 'DynamicBoolean[]';

    /** Whether the property refers to other components. */
    public function isReference(): bool
    {
        return $this === self::ComponentId || $this === self::ChildList || $this === self::Tabs;
    }
}
