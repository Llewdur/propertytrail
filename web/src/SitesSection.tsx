import { Button } from '@zekini/ui';
import { useState } from 'react';

export type Area = { id: number; name: string; room_type: string };

export type Site = {
    id: number;
    name: string;
    site_type: string;
    areas: Area[];
    units: { id: number; name: string }[];
};

const SITE_TYPES = [
    { value: 'standalone_house', label: 'Standalone house' },
    { value: 'sectional_title', label: 'Sectional title' },
    { value: 'multi_unit', label: 'Several units on one property' },
];

type Props = {
    sites: Site[];
    send: (url: string, body: unknown) => Promise<Response>;
    apiBase: string;
    failureMessages: (response: Response, fallback: string) => Promise<string[]>;
    onSaved: () => void;
};

export default function SitesSection({ sites, send, apiBase, failureMessages, onSaved }: Props) {
    const [siteName, setSiteName] = useState('');
    const [siteType, setSiteType] = useState(SITE_TYPES[0]!.value);
    const [siteId, setSiteId] = useState('');
    const [areaName, setAreaName] = useState('');
    const [errors, setErrors] = useState<string[]>([]);

    async function save(url: string, body: unknown, fallback: string, done: () => void): Promise<void> {
        setErrors([]);
        const response = await send(url, body);

        if (!response.ok) {
            setErrors(await failureMessages(response, fallback));
            return;
        }

        done();
        onSaved();
    }

    const fieldClass = 'mt-1 w-full rounded border border-slate-300 p-2 text-sm';

    return (
        <div className="mt-6">
            <h3 className="font-medium">Sites and shared areas</h3>
            <p className="mt-1 text-sm text-slate-500">
                A site holds one or more units. Add its shared and outdoor areas here, then choose on each lease which ones it covers.
            </p>
            {errors.length > 0 && (
                <ul role="alert" className="mt-2 space-y-1 text-sm text-red-600">
                    {errors.map((message) => <li key={message}>{message}</li>)}
                </ul>
            )}
            <div className="mt-3 grid gap-6 lg:grid-cols-2">
                <div className="space-y-3">
                    <label className="block text-sm">
                        Site name
                        <input className={fieldClass} value={siteName} onChange={(event) => setSiteName(event.target.value)} />
                    </label>
                    <label className="block text-sm">
                        Site type
                        <select className={fieldClass} value={siteType} onChange={(event) => setSiteType(event.target.value)}>
                            {SITE_TYPES.map((type) => <option key={type.value} value={type.value}>{type.label}</option>)}
                        </select>
                    </label>
                    <Button onClick={() => void save(
                        `${apiBase}/sites`,
                        { name: siteName, site_type: siteType },
                        'Could not add the site.',
                        () => setSiteName(''),
                    )}>Add site</Button>
                </div>
                <div className="space-y-3">
                    <label className="block text-sm">
                        Shared area site
                        <select className={fieldClass} value={siteId} onChange={(event) => setSiteId(event.target.value)}>
                            <option value="">Select…</option>
                            {sites.map((site) => <option key={site.id} value={site.id}>{site.name}</option>)}
                        </select>
                    </label>
                    <label className="block text-sm">
                        Shared or outdoor area name
                        <input className={fieldClass} value={areaName} onChange={(event) => setAreaName(event.target.value)} />
                    </label>
                    <Button onClick={() => {
                        if (siteId === '') {
                            setErrors(['Choose a site first.']);
                            return;
                        }

                        void save(
                            `${apiBase}/sites/${siteId}/areas`,
                            { name: areaName, room_type: 'outdoor' },
                            'Could not add the area.',
                            () => setAreaName(''),
                        );
                    }}>Add shared area</Button>
                </div>
            </div>
            <ul className="mt-3 space-y-1 text-sm">
                {sites.map((site) => (
                    <li key={site.id}>
                        {site.name}: {site.units.length} unit(s); shared areas: {site.areas.map((area) => area.name).join(', ') || 'none'}
                    </li>
                ))}
            </ul>
        </div>
    );
}
