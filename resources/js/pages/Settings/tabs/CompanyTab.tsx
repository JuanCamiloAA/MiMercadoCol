import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import { Input } from '@/components/ui/Field';
import { SaveBar, TabHeader } from '../SettingField';
import type { TabProps } from '../types';

/**
 * Ajustes > Empresa: los datos legales que salen impresos en tiquetes e informes. El logo y el
 * color no van aqui: la marca vive en su propia pestana.
 */
export function CompanyTab({ company, readOnly, onDirtyChange }: TabProps) {
    const form = useForm({
        name: company?.name ?? '',
        legal_name: company?.legal_name ?? '',
        tax_id: company?.tax_id ?? '',
        email: company?.email ?? '',
        phone: company?.phone ?? '',
        address: company?.address ?? '',
        city: company?.city ?? '',
    });
    const { data, setData, errors } = form;

    useEffect(() => {
        onDirtyChange(form.isDirty);
    }, [form.isDirty, onDirtyChange]);

    const save = () => {
        form.put(route('settings.company.update'), { preserveScroll: true, preserveState: true, onSuccess: () => form.setDefaults() });
    };

    return (
        <div className="flex max-w-[40rem] flex-col gap-5">
            <TabHeader scope="Empresa · salen en tiquetes, informes y en la landing" />

            <div className="grid gap-3 sm:grid-cols-2">
                <Input label="Nombre comercial" required disabled={readOnly} value={data.name} onChange={(e) => setData('name', e.target.value)} error={errors.name} containerClassName="sm:col-span-2" />
                <Input label="Razón social" required disabled={readOnly} value={data.legal_name} onChange={(e) => setData('legal_name', e.target.value)} error={errors.legal_name} containerClassName="sm:col-span-2" />
                <Input label="NIT" required disabled={readOnly} value={data.tax_id} onChange={(e) => setData('tax_id', e.target.value)} error={errors.tax_id} />
                <Input label="Ciudad" disabled={readOnly} value={data.city} onChange={(e) => setData('city', e.target.value)} error={errors.city} />
                <Input label="Correo" type="email" disabled={readOnly} value={data.email} onChange={(e) => setData('email', e.target.value)} error={errors.email} />
                <Input label="Teléfono" disabled={readOnly} value={data.phone} onChange={(e) => setData('phone', e.target.value)} error={errors.phone} />
                <Input label="Dirección" disabled={readOnly} value={data.address} onChange={(e) => setData('address', e.target.value)} error={errors.address} containerClassName="sm:col-span-2" />
            </div>

            {!readOnly && <SaveBar dirty={form.isDirty} processing={form.processing} onSave={save} label="Guardar los datos" />}
        </div>
    );
}
