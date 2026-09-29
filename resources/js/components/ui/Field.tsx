import type { InputHTMLAttributes, ReactNode, SelectHTMLAttributes, TextareaHTMLAttributes } from 'react';
import { useId } from 'react';
import { cn } from '@/lib/cn';

const CONTROL =
    'w-full rounded-control border border-[var(--line)] bg-[var(--surface)] px-3 text-sm text-[var(--text)] ' +
    'placeholder:text-[var(--muted)]/70 transition-[border-color,box-shadow] ' +
    'focus:border-[var(--accent)] focus:outline-none focus:ring-2 focus:ring-[var(--accent)]/25 ' +
    'disabled:bg-[var(--surface-2)] disabled:text-[var(--muted)]';

interface LabelProps {
    label?: string;
    hint?: string;
    error?: string;
    required?: boolean;
    children: ReactNode;
    htmlFor?: string;
    className?: string;
}

export function Field({ label, hint, error, required, children, htmlFor, className }: LabelProps) {
    return (
        <div className={cn('flex flex-col gap-1.5', className)}>
            {label && (
                <label htmlFor={htmlFor} className="text-[0.8125rem] font-medium text-[var(--text)]">
                    {label}
                    {required && <span className="ml-0.5 text-[var(--danger)]">*</span>}
                </label>
            )}
            {children}
            {error ? (
                <p className="text-[0.75rem] font-medium text-[var(--danger)]">{error}</p>
            ) : hint ? (
                <p className="text-[0.75rem] text-[var(--muted)]">{hint}</p>
            ) : null}
        </div>
    );
}

interface InputProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'size' | 'prefix'> {
    label?: string;
    hint?: string;
    error?: string;
    prefix?: ReactNode;
    suffix?: ReactNode;
    big?: boolean;
    containerClassName?: string;
}

export function Input({ label, hint, error, required, prefix, suffix, big, className, containerClassName, ...props }: InputProps) {
    const generatedId = useId();
    const id = props.id ?? generatedId;

    return (
        <Field label={label} hint={hint} error={error} required={required} htmlFor={id} className={containerClassName}>
            <div className="relative flex items-center">
                {prefix && <span className="pointer-events-none absolute left-3 text-[var(--muted)]">{prefix}</span>}
                <input
                    id={id}
                    className={cn(
                        CONTROL,
                        // `big` es el de la caja: no se encoge con la densidad.
                        big ? 'h-11' : 'h-[calc(2.5rem*var(--density))]',
                        prefix && 'pl-9',
                        suffix && 'pr-10',
                        error && 'border-[var(--danger)]',
                        className,
                    )}
                    {...props}
                />
                {suffix && <span className="absolute right-3 text-[var(--muted)]">{suffix}</span>}
            </div>
        </Field>
    );
}

interface TextareaProps extends TextareaHTMLAttributes<HTMLTextAreaElement> {
    label?: string;
    hint?: string;
    error?: string;
}

export function Textarea({ label, hint, error, required, className, ...props }: TextareaProps) {
    const generatedId = useId();
    const id = props.id ?? generatedId;

    return (
        <Field label={label} hint={hint} error={error} required={required} htmlFor={id}>
            <textarea id={id} className={cn(CONTROL, 'min-h-[5.25rem] py-2.5', error && 'border-[var(--danger)]', className)} {...props} />
        </Field>
    );
}

export interface SelectOptionItem {
    value: string | number;
    label: string;
    disabled?: boolean;
}

interface SelectProps extends Omit<SelectHTMLAttributes<HTMLSelectElement>, 'children'> {
    label?: string;
    hint?: string;
    error?: string;
    options: SelectOptionItem[];
    placeholder?: string;
    big?: boolean;
}

export function Select({ label, hint, error, required, options, placeholder, big, className, ...props }: SelectProps) {
    const generatedId = useId();
    const id = props.id ?? generatedId;

    return (
        <Field label={label} hint={hint} error={error} required={required} htmlFor={id}>
            <select
                id={id}
                className={cn(CONTROL, big ? 'h-11' : 'h-[calc(2.5rem*var(--density))]', 'appearance-none pr-8', error && 'border-[var(--danger)]', className)}
                style={{
                    backgroundImage:
                        "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23565E64' stroke-width='2.5' stroke-linecap='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E\")",
                    backgroundRepeat: 'no-repeat',
                    backgroundPosition: 'right 10px center',
                }}
                {...props}
            >
                {placeholder !== undefined && <option value="">{placeholder}</option>}
                {options.map((option) => (
                    <option key={option.value} value={option.value} disabled={option.disabled}>
                        {option.label}
                    </option>
                ))}
            </select>
        </Field>
    );
}

interface CheckboxProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type'> {
    label: ReactNode;
    hint?: string;
}

export function Checkbox({ label, hint, className, ...props }: CheckboxProps) {
    const generatedId = useId();
    const id = props.id ?? generatedId;

    return (
        <div className="flex items-start gap-2.5">
            <input
                id={id}
                type="checkbox"
                className={cn(
                    'mt-0.5 size-[1.125rem] shrink-0 cursor-pointer rounded-chip border border-[var(--line-2)] bg-[var(--surface)]',
                    'accent-[var(--accent)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--accent)]',
                    className,
                )}
                {...props}
            />
            <label htmlFor={id} className="cursor-pointer select-none text-sm leading-tight text-[var(--text)]">
                {label}
                {hint && <span className="mt-0.5 block text-[0.75rem] font-normal text-[var(--muted)]">{hint}</span>}
            </label>
        </div>
    );
}

interface ToggleProps {
    checked: boolean;
    onChange: (checked: boolean) => void;
    label?: ReactNode;
    hint?: string;
    disabled?: boolean;
    id?: string;
}

export function Toggle({ checked, onChange, label, hint, disabled, id }: ToggleProps) {
    const generatedId = useId();
    const controlId = id ?? generatedId;

    return (
        <div className="flex items-start gap-3">
            <button
                id={controlId}
                type="button"
                role="switch"
                aria-checked={checked}
                disabled={disabled}
                onClick={() => onChange(!checked)}
                className={cn(
                    'relative mt-0.5 h-[1.375rem] w-[2.375rem] shrink-0 rounded-full border transition-colors',
                    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--accent)]',
                    checked ? 'border-[var(--accent)] bg-[var(--accent)]' : 'border-[var(--line-2)] bg-[var(--surface-3)]',
                    disabled && 'cursor-not-allowed opacity-50',
                )}
            >
                <span
                    className={cn(
                        'absolute top-1/2 size-[1rem] -translate-y-1/2 rounded-full bg-white shadow-sm transition-[left]',
                        checked ? 'left-[1.1875rem]' : 'left-[0.125rem]',
                    )}
                />
            </button>
            {label && (
                <label htmlFor={controlId} className="cursor-pointer select-none text-sm leading-tight text-[var(--text)]">
                    {label}
                    {hint && <span className="mt-0.5 block text-[0.75rem] font-normal text-[var(--muted)]">{hint}</span>}
                </label>
            )}
        </div>
    );
}
