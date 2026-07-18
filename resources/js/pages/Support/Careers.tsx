import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

export default function Careers() {
    const [formData, setFormData] = useState({
        full_name: '',
        email: '',
        phone: '',
        position_applied: '',
        cover_letter: '',
        linkedin_url: '',
        portfolio_url: '',
    });
    const [resume, setResume] = useState<File | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        setProcessing(true);

        const data = new FormData();
        Object.entries(formData).forEach(([key, value]) => {
            if (value) {
                data.append(key, value);
            }
        });
        if (resume) {
            data.append('resume', resume);
        }

        router.post('/support/careers', data, {
            preserveScroll: true,
            onSuccess: () => {
                setFormData({
                    full_name: '',
                    email: '',
                    phone: '',
                    position_applied: '',
                    cover_letter: '',
                    linkedin_url: '',
                    portfolio_url: '',
                });
                setResume(null);
                setErrors({});
            },
            onError: (errors) => {
                setErrors(errors);
            },
            onFinish: () => {
                setProcessing(false);
            },
        });
    };

    return (
        <AppLayout>
            <Head title="Careers" />
            <div className="mx-auto max-w-3xl space-y-6 py-8">
                {/* Header */}
                <div className="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                    <h1 className="text-3xl font-bold text-gray-900 dark:text-gray-100">
                        👔 Join Our Team
                    </h1>
                    <p className="mt-2 text-gray-600 dark:text-gray-400">
                        We're always looking for talented individuals to join
                        our growing team. Apply now and help us build the future
                        of investment tracking!
                    </p>
                </div>

                {/* Open Positions */}
                <div className="rounded-lg border border-gray-200 bg-gradient-to-br from-blue-50 to-indigo-50 p-6 dark:border-gray-700 dark:from-gray-800 dark:to-gray-900">
                    <h2 className="mb-4 text-xl font-semibold text-gray-900 dark:text-gray-100">
                        🎯 Open Positions
                    </h2>
                    <div className="space-y-3">
                        <div className="rounded-lg border border-blue-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                            <h3 className="font-semibold text-gray-900 dark:text-gray-100">
                                Full Stack Developer
                            </h3>
                            <p className="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                Remote • Full-time • Laravel, React, TypeScript
                            </p>
                        </div>
                        <div className="rounded-lg border border-blue-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                            <h3 className="font-semibold text-gray-900 dark:text-gray-100">
                                Data Engineer
                            </h3>
                            <p className="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                Remote • Full-time • Python, MySQL, APIs
                            </p>
                        </div>
                        <div className="rounded-lg border border-blue-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                            <h3 className="font-semibold text-gray-900 dark:text-gray-100">
                                Product Designer
                            </h3>
                            <p className="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                Remote • Full-time • Figma, UI/UX Design
                            </p>
                        </div>
                    </div>
                </div>

                {/* Application Form */}
                <div className="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                    <h2 className="mb-6 text-xl font-semibold text-gray-900 dark:text-gray-100">
                        Application Form
                    </h2>
                    <form onSubmit={handleSubmit} className="space-y-6">
                        <div>
                            <Label htmlFor="full_name">Full Name</Label>
                            <Input
                                id="full_name"
                                type="text"
                                value={formData.full_name}
                                onChange={(e) =>
                                    setFormData({
                                        ...formData,
                                        full_name: e.target.value,
                                    })
                                }
                                className="mt-1"
                            />
                            <InputError
                                message={errors.full_name}
                                className="mt-2"
                            />
                        </div>

                        <div className="grid gap-6 sm:grid-cols-2">
                            <div>
                                <Label htmlFor="email">Email Address</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    value={formData.email}
                                    onChange={(e) =>
                                        setFormData({
                                            ...formData,
                                            email: e.target.value,
                                        })
                                    }
                                    className="mt-1"
                                />
                                <InputError
                                    message={errors.email}
                                    className="mt-2"
                                />
                            </div>

                            <div>
                                <Label htmlFor="phone">
                                    Phone Number (Optional)
                                </Label>
                                <Input
                                    id="phone"
                                    type="tel"
                                    value={formData.phone}
                                    onChange={(e) =>
                                        setFormData({
                                            ...formData,
                                            phone: e.target.value,
                                        })
                                    }
                                    className="mt-1"
                                />
                                <InputError
                                    message={errors.phone}
                                    className="mt-2"
                                />
                            </div>
                        </div>

                        <div>
                            <Label htmlFor="position_applied">
                                Position Applied For
                            </Label>
                            <Input
                                id="position_applied"
                                type="text"
                                value={formData.position_applied}
                                onChange={(e) =>
                                    setFormData({
                                        ...formData,
                                        position_applied: e.target.value,
                                    })
                                }
                                className="mt-1"
                                placeholder="e.g., Full Stack Developer"
                            />
                            <InputError
                                message={errors.position_applied}
                                className="mt-2"
                            />
                        </div>

                        <div className="grid gap-6 sm:grid-cols-2">
                            <div>
                                <Label htmlFor="linkedin_url">
                                    LinkedIn Profile (Optional)
                                </Label>
                                <Input
                                    id="linkedin_url"
                                    type="url"
                                    value={formData.linkedin_url}
                                    onChange={(e) =>
                                        setFormData({
                                            ...formData,
                                            linkedin_url: e.target.value,
                                        })
                                    }
                                    className="mt-1"
                                    placeholder="https://linkedin.com/in/..."
                                />
                                <InputError
                                    message={errors.linkedin_url}
                                    className="mt-2"
                                />
                            </div>

                            <div>
                                <Label htmlFor="portfolio_url">
                                    Portfolio/Website (Optional)
                                </Label>
                                <Input
                                    id="portfolio_url"
                                    type="url"
                                    value={formData.portfolio_url}
                                    onChange={(e) =>
                                        setFormData({
                                            ...formData,
                                            portfolio_url: e.target.value,
                                        })
                                    }
                                    className="mt-1"
                                    placeholder="https://..."
                                />
                                <InputError
                                    message={errors.portfolio_url}
                                    className="mt-2"
                                />
                            </div>
                        </div>

                        <div>
                            <Label htmlFor="resume">Resume/CV (Optional)</Label>
                            <input
                                id="resume"
                                type="file"
                                accept=".pdf,.doc,.docx"
                                onChange={(e) =>
                                    setResume(e.target.files?.[0] || null)
                                }
                                className="mt-1 w-full text-sm text-gray-600 file:mr-4 file:rounded file:border-0 file:bg-blue-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-blue-700 hover:file:bg-blue-100 dark:text-gray-400 dark:file:bg-gray-700 dark:file:text-gray-300"
                            />
                            <p className="mt-1 text-xs text-gray-500">
                                PDF, DOC, or DOCX (max 5MB)
                            </p>
                            <InputError
                                message={errors.resume}
                                className="mt-2"
                            />
                        </div>

                        <div>
                            <Label htmlFor="cover_letter">
                                Cover Letter (Optional)
                            </Label>
                            <textarea
                                id="cover_letter"
                                value={formData.cover_letter}
                                onChange={(e) =>
                                    setFormData({
                                        ...formData,
                                        cover_letter: e.target.value,
                                    })
                                }
                                rows={6}
                                className="mt-1 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                                placeholder="Tell us why you're interested in this position and what makes you a great fit..."
                            />
                            <InputError
                                message={errors.cover_letter}
                                className="mt-2"
                            />
                        </div>

                        <Button
                            type="submit"
                            disabled={processing}
                            className="w-full"
                        >
                            {processing
                                ? 'Submitting Application...'
                                : 'Submit Application'}
                        </Button>
                    </form>
                </div>
            </div>
        </AppLayout>
    );
}
