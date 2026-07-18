import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

export default function HelpDesk() {
    const [formData, setFormData] = useState({
        name: '',
        email: '',
        subject: '',
        message: '',
        priority: 'medium',
    });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        setProcessing(true);

        router.post('/support/help-desk', formData, {
            preserveScroll: true,
            onSuccess: () => {
                setFormData({
                    name: '',
                    email: '',
                    subject: '',
                    message: '',
                    priority: 'medium',
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
            <Head title="Help Desk" />
            <div className="mx-auto max-w-3xl space-y-6 py-8">
                {/* Header */}
                <div className="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                    <h1 className="text-3xl font-bold text-gray-900 dark:text-gray-100">
                        🎫 Help Desk
                    </h1>
                    <p className="mt-2 text-gray-600 dark:text-gray-400">
                        Need help? Submit a support ticket and our team will get
                        back to you as soon as possible.
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
                            <Label htmlFor="subject">Subject</Label>
                            <Input
                                id="subject"
                                type="text"
                                value={formData.subject}
                                onChange={(e) =>
                                    setFormData({
                                        ...formData,
                                        subject: e.target.value,
                                    })
                                }
                                className="mt-1"
                                placeholder="Brief description of your issue"
                            />
                            <InputError
                                message={errors.subject}
                                className="mt-2"
                            />
                        </div>

                        <div>
                            <Label htmlFor="priority">Priority</Label>
                            <select
                                id="priority"
                                value={formData.priority}
                                onChange={(e) =>
                                    setFormData({
                                        ...formData,
                                        priority: e.target.value,
                                    })
                                }
                                className="mt-1 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                            >
                                <option value="low">
                                    Low - General question
                                </option>
                                <option value="medium">
                                    Medium - Need assistance
                                </option>
                                <option value="high">
                                    High - Blocking issue
                                </option>
                                <option value="urgent">
                                    Urgent - Critical problem
                                </option>
                            </select>
                            <InputError
                                message={errors.priority}
                                className="mt-2"
                            />
                        </div>

                        <div>
                            <Label htmlFor="message">Message</Label>
                            <textarea
                                id="message"
                                value={formData.message}
                                onChange={(e) =>
                                    setFormData({
                                        ...formData,
                                        message: e.target.value,
                                    })
                                }
                                rows={6}
                                className="mt-1 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                                placeholder="Please provide as much detail as possible..."
                            />
                            <InputError
                                message={errors.message}
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
                                : 'Submit Support Ticket'}
                        </Button>
                    </form>
                </div>
            </div>
        </AppLayout>
    );
}
