import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Mail, MessageSquare, TrendingUp } from 'lucide-react';
import { FormEventHandler } from 'react';

export default function Contact() {
    const { data, setData, post, processing, errors, reset, wasSuccessful } =
        useForm({
            name: '',
            email: '',
            subject: '',
            message: '',
        });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/contact', {
            onSuccess: () => reset(),
        });
    };

    return (
        <>
            <Head title="Contact Us - tikrTracker" />

            <div className="min-h-screen bg-gradient-to-b from-white to-gray-50 dark:from-gray-950 dark:to-gray-900">
                {/* Header */}
                <header className="border-b border-gray-200 dark:border-gray-800">
                    <div className="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
                        <Link href="/" className="flex items-center gap-2">
                            <TrendingUp className="size-8 text-emerald-600" />
                            <span className="text-2xl font-bold text-gray-900 dark:text-white">
                                tikrTracker
                            </span>
                        </Link>
                        <nav className="flex items-center gap-4">
                            <Link href="/">
                                <Button variant="ghost" className="gap-2">
                                    <ArrowLeft className="size-4" />
                                    Back to Home
                                </Button>
                            </Link>
                            <Link href="/login">
                                <Button>Sign In</Button>
                            </Link>
                        </nav>
                    </div>
                </header>

                {/* Contact Section */}
                <section className="px-4 py-20 sm:px-6 lg:px-8">
                    <div className="mx-auto max-w-4xl">
                        <div className="mb-12 text-center">
                            <div className="mb-4 inline-flex rounded-full bg-emerald-100 p-4 dark:bg-emerald-900/30">
                                <Mail className="size-8 text-emerald-600 dark:text-emerald-400" />
                            </div>
                            <h1 className="mb-4 text-4xl font-bold text-gray-900 dark:text-white">
                                Get in Touch
                            </h1>
                            <p className="text-lg text-gray-600 dark:text-gray-400">
                                Have questions? Want to request beta access?
                                We'd love to hear from you.
                            </p>
                        </div>

                        <div className="grid gap-8 lg:grid-cols-3">
                            {/* Contact Info */}
                            <div className="space-y-6 lg:col-span-1">
                                <div className="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
                                    <h2 className="mb-4 text-lg font-semibold text-gray-900 dark:text-white">
                                        Contact Information
                                    </h2>
                                    <div className="space-y-4 text-sm text-gray-600 dark:text-gray-400">
                                        <div className="flex items-start gap-3">
                                            <MessageSquare className="mt-1 size-5 text-emerald-600" />
                                            <div>
                                                <p className="font-medium text-gray-900 dark:text-white">
                                                    Response Time
                                                </p>
                                                <p>
                                                    We typically respond within
                                                    24-48 hours
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div className="rounded-xl border border-blue-200 bg-blue-50 p-6 dark:border-blue-800 dark:bg-blue-900/20">
                                    <h3 className="mb-2 font-semibold text-blue-900 dark:text-blue-100">
                                        Beta Access Requests
                                    </h3>
                                    <p className="text-sm text-blue-700 dark:text-blue-300">
                                        Interested in early access? Mention
                                        "Beta Access" in your subject line and
                                        tell us why you'd like to join our
                                        testing program.
                                    </p>
                                </div>
                            </div>

                            {/* Contact Form */}
                            <div className="rounded-xl border border-gray-200 bg-white p-8 shadow-sm lg:col-span-2 dark:border-gray-800 dark:bg-gray-900">
                                {wasSuccessful && (
                                    <div className="mb-6 rounded-lg bg-emerald-100 p-4 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400">
                                        ✓ Thank you for your message! We'll get
                                        back to you soon.
                                    </div>
                                )}

                                <form onSubmit={submit} className="space-y-6">
                                    <div>
                                        <Label htmlFor="name">Name *</Label>
                                        <Input
                                            id="name"
                                            type="text"
                                            value={data.name}
                                            onChange={(e) =>
                                                setData('name', e.target.value)
                                            }
                                            required
                                            className="mt-1"
                                            placeholder="Your full name"
                                        />
                                        {errors.name && (
                                            <p className="mt-1 text-sm text-red-600 dark:text-red-400">
                                                {errors.name}
                                            </p>
                                        )}
                                    </div>

                                    <div>
                                        <Label htmlFor="email">Email *</Label>
                                        <Input
                                            id="email"
                                            type="email"
                                            value={data.email}
                                            onChange={(e) =>
                                                setData('email', e.target.value)
                                            }
                                            required
                                            className="mt-1"
                                            placeholder="your.email@example.com"
                                        />
                                        {errors.email && (
                                            <p className="mt-1 text-sm text-red-600 dark:text-red-400">
                                                {errors.email}
                                            </p>
                                        )}
                                    </div>

                                    <div>
                                        <Label htmlFor="subject">
                                            Subject *
                                        </Label>
                                        <Input
                                            id="subject"
                                            type="text"
                                            value={data.subject}
                                            onChange={(e) =>
                                                setData(
                                                    'subject',
                                                    e.target.value,
                                                )
                                            }
                                            required
                                            className="mt-1"
                                            placeholder="e.g., Beta Access Request, General Inquiry"
                                        />
                                        {errors.subject && (
                                            <p className="mt-1 text-sm text-red-600 dark:text-red-400">
                                                {errors.subject}
                                            </p>
                                        )}
                                    </div>

                                    <div>
                                        <Label htmlFor="message">
                                            Message *
                                        </Label>
                                        <textarea
                                            id="message"
                                            value={data.message}
                                            onChange={(e) =>
                                                setData(
                                                    'message',
                                                    e.target.value,
                                                )
                                            }
                                            required
                                            rows={6}
                                            className="mt-1 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                                            placeholder="Tell us what's on your mind..."
                                        />
                                        {errors.message && (
                                            <p className="mt-1 text-sm text-red-600 dark:text-red-400">
                                                {errors.message}
                                            </p>
                                        )}
                                    </div>

                                    <Button
                                        type="submit"
                                        size="lg"
                                        className="w-full"
                                        disabled={processing}
                                    >
                                        {processing
                                            ? 'Sending...'
                                            : 'Send Message'}
                                    </Button>
                                </form>
                            </div>
                        </div>
                    </div>
                </section>

                {/* FAQ Section */}
                <section className="bg-gray-100 px-4 py-20 sm:px-6 lg:px-8 dark:bg-gray-800/50">
                    <div className="mx-auto max-w-4xl">
                        <h2 className="mb-8 text-center text-3xl font-bold text-gray-900 dark:text-white">
                            Frequently Asked Questions
                        </h2>
                        <div className="space-y-6">
                            <div className="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
                                <h3 className="mb-2 text-lg font-semibold text-gray-900 dark:text-white">
                                    Is tikrTracker free to use?
                                </h3>
                                <p className="text-gray-600 dark:text-gray-400">
                                    During the beta phase, tikrTracker is
                                    available for testing at no cost. Pricing
                                    details will be announced before the
                                    official launch.
                                </p>
                            </div>

                            <div className="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
                                <h3 className="mb-2 text-lg font-semibold text-gray-900 dark:text-white">
                                    Can I create an account?
                                </h3>
                                <p className="text-gray-600 dark:text-gray-400">
                                    New user registration is currently disabled
                                    during beta testing. You can explore the
                                    platform in guest mode or request beta
                                    access through our contact form.
                                </p>
                            </div>

                            <div className="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
                                <h3 className="mb-2 text-lg font-semibold text-gray-900 dark:text-white">
                                    What markets do you cover?
                                </h3>
                                <p className="text-gray-600 dark:text-gray-400">
                                    We provide data for all S&P 500 stocks plus
                                    major cryptocurrencies, with 5-minute,
                                    hourly, and daily price updates.
                                </p>
                            </div>

                            <div className="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
                                <h3 className="mb-2 text-lg font-semibold text-gray-900 dark:text-white">
                                    When will tikrTracker officially launch?
                                </h3>
                                <p className="text-gray-600 dark:text-gray-400">
                                    We're currently refining features based on
                                    beta tester feedback. Stay tuned for launch
                                    announcements by contacting us to join our
                                    mailing list.
                                </p>
                            </div>
                        </div>
                    </div>
                </section>

                {/* Footer */}
                <footer className="border-t border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-950">
                    <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                        <p className="text-center text-sm text-gray-500 dark:text-gray-500">
                            © {new Date().getFullYear()} tikrTracker. All
                            rights reserved. Beta Version.
                        </p>
                    </div>
                </footer>
            </div>
        </>
    );
}
