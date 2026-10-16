/** TypeScript model of a Form Definition (spec §2–§3). All properties are read-only. */
import type { JsonValue } from '../json/types.ts';

/** A JsonLogic expression or literal (§4). */
export type Logic = JsonValue;

type Extensions = Readonly<Partial<Record<`x-${string}`, unknown>>>;

export type LangMap = Readonly<Record<string, string>>;

export type I18n = Readonly<{ label?: LangMap; help?: LangMap; placeholder?: LangMap }> &
    Extensions;

export type Option = Readonly<{
    value: string;
    label: string;
    i18n?: { readonly label?: LangMap };
}> &
    Extensions;

export type PdfHints = Readonly<{
    hidden?: boolean;
    label?: string;
    width?: 'full' | 'half' | 'third';
    keepTogether?: boolean;
}> &
    Extensions;

export type NumberLike = number | string;

export type Validation = Readonly<{
    min?: NumberLike;
    max?: NumberLike;
    minLength?: number;
    maxLength?: number;
    pattern?: string;
    step?: NumberLike;
    accept?: readonly string[];
    maxFiles?: number;
    maxSizeKb?: number;
}> &
    Extensions;

type Common = Readonly<{
    id: string;
    key: string;
    label?: string;
    help?: string;
    placeholder?: string;
    required?: boolean;
    visibleIf?: Logic;
    requiredIf?: Logic;
    readOnlyIf?: Logic;
    default?: Logic;
    validation?: Validation;
    pdf?: PdfHints;
    role?: string;
    i18n?: I18n;
}> &
    Extensions;

type Simple<T extends string> = Common & { readonly type: T };

export type TextElement = Simple<'text'>;
export type TextareaElement = Simple<'textarea'> & { readonly rows?: number };
export type EmailElement = Simple<'email'>;
export type PhoneElement = Simple<'phone'>;
export type NumberElement = Simple<'number'>;
export type MoneyElement = Simple<'money'> & { readonly currency: string; readonly scale?: number };
export type DateElement = Simple<'date'>;
export type TimeElement = Simple<'time'>;
export type DateTimeElement = Simple<'datetime'>;
export type ChoiceElement = Simple<'select' | 'radio' | 'multiselect'> & {
    readonly options: readonly Option[];
};
export type CheckboxElement = Simple<'checkbox'>;
export type ToggleElement = Simple<'toggle'>;
export type RatingElement = Simple<'rating'> & { readonly max?: number };
export type AttachmentElement = Simple<'photo' | 'file'> & { readonly multiple?: boolean };
export type SignatureElement = Simple<'signature'> & { readonly signerNameFrom?: string };
export type LocationElement = Simple<'location'>;
export type CalculatedElement = Simple<'calculated'> & {
    readonly expression: Logic;
    readonly format?: 'number' | 'money' | 'text';
    readonly scale?: number;
    readonly currency?: string;
};
export type HiddenElement = Simple<'hidden'>;
export type RepeaterElement = Simple<'repeater'> & {
    readonly elements: readonly FormElement[];
    readonly display?: 'cards' | 'table';
    readonly minItems?: number;
    readonly maxItems?: number;
    readonly addLabel?: string;
    readonly itemLabel?: string;
};
export type SectionElement = Simple<'section'> & {
    readonly elements: readonly FormElement[];
    readonly collapsible?: boolean;
};
export type Column = Readonly<{ id?: string; elements: readonly FormElement[] }> & Extensions;
export type ColumnsElement = Simple<'columns'> & { readonly columns: readonly Column[] };
export type HeadingElement = Simple<'heading'> & {
    readonly text: string;
    readonly level?: 1 | 2 | 3;
};
export type ParagraphElement = Simple<'paragraph'> & { readonly text: string };
export type DividerElement = Simple<'divider'>;
export type ImageElement = Simple<'image'> & { readonly src: string; readonly alt?: string };
export type PageBreakElement = Simple<'page_break'>;

/** Element union discriminated by `type`. */
export type FormElement =
    | TextElement
    | TextareaElement
    | EmailElement
    | PhoneElement
    | NumberElement
    | MoneyElement
    | DateElement
    | TimeElement
    | DateTimeElement
    | ChoiceElement
    | CheckboxElement
    | ToggleElement
    | RatingElement
    | AttachmentElement
    | SignatureElement
    | LocationElement
    | CalculatedElement
    | HiddenElement
    | RepeaterElement
    | SectionElement
    | ColumnsElement
    | HeadingElement
    | ParagraphElement
    | DividerElement
    | ImageElement
    | PageBreakElement;

export type ElementType = FormElement['type'];

export type Page = Readonly<{
    id?: string;
    key: string;
    title: string;
    description?: string;
    visibleIf?: Logic;
    elements: readonly FormElement[];
}> &
    Extensions;

export type NumberingSettings = Readonly<{
    format: string;
    reset?: 'never' | 'yearly' | 'monthly';
}>;

export type Settings = Readonly<{
    layout?: 'wizard' | 'scroll';
    numbering?: NumberingSettings;
    document?: Readonly<{ design?: string }>;
    sendToCustomer?: boolean;
}> &
    Extensions;

export type TemplateSource = Readonly<{ registry?: string; template: string; version: string }>;

export type FormDefinition = Readonly<{
    $schema?: string;
    specVersion: string;
    key: string;
    title: string;
    description?: string;
    locale: string;
    settings?: Settings;
    pages: readonly Page[];
    source?: TemplateSource;
}> &
    Extensions;

/** Submission data: values keyed by element key; repeaters hold arrays of row objects. */
export type SubmissionData = Readonly<Record<string, JsonValue>>;
