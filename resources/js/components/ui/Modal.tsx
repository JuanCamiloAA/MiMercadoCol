import { Dialog, DialogPanel, DialogTitle, Transition, TransitionChild } from '@headlessui/react';
import { XIcon } from '@phosphor-icons/react';
import { Fragment, type ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { Button } from './Button';

interface ModalProps {
    open: boolean;
    onClose: () => void;
    title: ReactNode;
    description?: ReactNode;
    children: ReactNode;
    footer?: ReactNode;
    size?: 'sm' | 'md' | 'lg' | 'xl';
}

const SIZES = {
    sm: 'max-w-md',
    md: 'max-w-xl',
    lg: 'max-w-3xl',
    xl: 'max-w-5xl',
};

export function Modal({ open, onClose, title, description, children, footer, size = 'md' }: ModalProps) {
    return (
        <Transition show={open} as={Fragment}>
            <Dialog onClose={onClose} className="relative z-50">
                <TransitionChild
                    as={Fragment}
                    enter="duration-150 ease-out"
                    enterFrom="opacity-0"
                    enterTo="opacity-100"
                    leave="duration-100 ease-in"
                    leaveFrom="opacity-100"
                    leaveTo="opacity-0"
                >
                    <div className="fixed inset-0 bg-black/45 backdrop-blur-[2px]" aria-hidden="true" />
                </TransitionChild>

                <div className="fixed inset-0 overflow-y-auto p-4 sm:p-6">
                    <div className="flex min-h-full items-center justify-center">
                        <TransitionChild
                            as={Fragment}
                            enter="duration-150 ease-out"
                            enterFrom="opacity-0 translate-y-2"
                            enterTo="opacity-100 translate-y-0"
                            leave="duration-100 ease-in"
                            leaveFrom="opacity-100"
                            leaveTo="opacity-0"
                        >
                            <DialogPanel
                                className={cn(
                                    'w-full rounded-card border border-[var(--line)] bg-[var(--surface)] shadow-xl',
                                    SIZES[size],
                                )}
                            >
                                <header className="flex items-start justify-between gap-4 border-b border-[var(--line)] px-5 py-4">
                                    <div>
                                        <DialogTitle className="text-[1rem] font-semibold tracking-tight text-[var(--text)]">
                                            {title}
                                        </DialogTitle>
                                        {description && <p className="mt-0.5 text-[0.8125rem] text-[var(--muted)]">{description}</p>}
                                    </div>
                                    <button
                                        type="button"
                                        onClick={onClose}
                                        aria-label="Cerrar"
                                        className="grid size-8 shrink-0 place-items-center rounded-chip text-[var(--muted)] transition-colors hover:bg-[var(--surface-2)] hover:text-[var(--text)]"
                                    >
                                        <XIcon size={18} />
                                    </button>
                                </header>

                                <div className="max-h-[70vh] overflow-y-auto px-5 py-4">{children}</div>

                                {footer && (
                                    <footer className="flex flex-wrap items-center justify-end gap-2 border-t border-[var(--line)] px-5 py-3.5">
                                        {footer}
                                    </footer>
                                )}
                            </DialogPanel>
                        </TransitionChild>
                    </div>
                </div>
            </Dialog>
        </Transition>
    );
}

interface ConfirmDialogProps {
    open: boolean;
    onClose: () => void;
    onConfirm: () => void;
    title: string;
    message: ReactNode;
    confirmLabel?: string;
    cancelLabel?: string;
    tone?: 'danger' | 'primary';
    loading?: boolean;
}

export function ConfirmDialog({
    open,
    onClose,
    onConfirm,
    title,
    message,
    confirmLabel = 'Confirmar',
    cancelLabel = 'Cancelar',
    tone = 'danger',
    loading = false,
}: ConfirmDialogProps) {
    return (
        <Modal
            open={open}
            onClose={onClose}
            title={title}
            size="sm"
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        {cancelLabel}
                    </Button>
                    <Button variant={tone === 'danger' ? 'danger' : 'primary'} onClick={onConfirm} loading={loading}>
                        {confirmLabel}
                    </Button>
                </>
            }
        >
            <div className="text-sm leading-relaxed text-[var(--text)]">{message}</div>
        </Modal>
    );
}
