import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

export default function FeatureRequest() {
    const [formData, setFormData] = useState({
        name: '',
        email: '',
        title: '',
        description: '',
        category: 'other',
    });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        setProcessing(true);

        router.post('/support/feature-request', formData, {
            preserveScroll: true,
            onSuccess: () => {
                setFormData({
                    name: '',
                    email: '',
                    title: '',
                    description: '',
                    category: 'other',
                });
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
            <Head title="Feature Request" />
            <div className="mx-auto max-w-3xl space-y-6 py-8">
                {/* Header */}
                <div className="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                    <h1 className="text-3xl font-bold text-gray-900 dark:text-gray-100">
                        💡 Feature Request
                    </h1>
                    <p className="mt-2 text-gray-600 dark:text-gray-400">
                        Have an idea to improve our platform? We'd love to hear
                        it! Your feedback helps us build better features.
                    </p>
                </div>

                {/* Form */}
                <div className="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                    <form onSubmit={handleSubmit} className="space-y-6">
                        <div>
                            <Label htmlFor="name">Your Name</Label>
                            <Input
                                id="name"
                                type="text"
                                value={formData.name}
                                onChange={(e) =>
                                    setFormData({
                                        ...formData,
                                        name: e.target.value,
                                    })
                                }
                                className="mt-1"
                            />
                            <InputError
                                message={errors.name}
                                className="mt-2"
                            />
                        </div>

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
                            <Label htmlFor="title">Feature Title</Label>
                            <Input
                                id="title"
                                type="text"
                                value={formData.title}
                                onChange={(e) =>
                                    setFormData({
                                        ...formData,
                                        title: e.target.value,
                                    })
                                }
                                className="mt-1"
                                placeholder="What feature would you like to see?"
                            />
                            <InputError
                                message={errors.title}
                                className="mt-2"
                            />
                        </div>

                        <div>
                            <Label htmlFor="category">Category</Label>
                            <select
                                id="category"
                                value={formData.category}
                                onChange={(e) =>
                                    setFormData({
                                        ...formData,
                                        category: e.target.value,
                                    })
                                }
                                className="mt-1 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                            >
                                <option value="ui_ux">UI/UX Improvement</option>
                                <option value="functionality">
                                    New Functionality
                                </option>
                                <option value="integration">
                                    Integration with Other Tools
                                </option>
                                <option value="performance">
                                    Performance Enhancement
                                </option>
                                <option value="other">Other</option>
                            </select>
                            <InputError
                                message={errors.category}
                                className="mt-2"
                            />
                        </div>

                        <div>
                            <Label htmlFor="description">
                                Detailed Description
                            </Label>
                            <textarea
                                id="description"
                                value={formData.description}
                                onChange={(e) =>
                                    setFormData({
                                        ...formData,
                                        description: e.target.value,
                                    })
                                }
                                rows={6}
                                className="mt-1 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                                placeholder="Describe your feature request in detail. What problem does it solve? How should it work?"
                            />
                            <InputError
                                message={errors.description}
                                className="mt-2"
                            />
                        </div>

                        <Button
                            type="submit"
                            disabled={processing}
                            className="w-full"
                        >
                            {processing
                                ? 'Submitting...'
                                : 'Submit Feature Request'}
                        </Button>
                    </form>
                </div>
            </div>
        </AppLayout>
    );
}
