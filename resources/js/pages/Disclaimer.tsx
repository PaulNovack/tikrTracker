import { Head, Link, useForm, router } from '@inertiajs/react';
import { Shield, AlertTriangle, DollarSign, FileText, Users, ChevronLeft, Check, Cookie } from 'lucide-react';
import { useState } from 'react';

interface DisclaimerProps {
    hasAcceptedDisclaimer?: boolean;
    isAuthenticated?: boolean;
}

export default function Disclaimer({ hasAcceptedDisclaimer = false, isAuthenticated = false }: DisclaimerProps) {
    const [acknowledged, setAcknowledged] = useState(false);
    const [cookiesAcknowledged, setCookiesAcknowledged] = useState(false);

    const { processing } = useForm({
        disclaimer_accepted: false,
        cookies_accepted: false,
    });

    const handleAcknowledge = () => {
        if (acknowledged && cookiesAcknowledged) {
            console.log('Submitting disclaimer acceptance...');
            
            // Use router.post to submit the form directly with success/error handling
            router.post('/disclaimer/accept', {
                disclaimer_accepted: true,
                cookies_accepted: true,
            }, {
                onSuccess: () => {
                    console.log('Disclaimer accepted successfully');
                },
                onError: (errors) => {
                    console.error('Disclaimer submission failed:', errors);
                },
                onFinish: () => {
                    console.log('Disclaimer submission finished');
                }
            });
        } else {
            console.log('Please acknowledge both disclaimer and cookies');
        }
    };

    return (
        <>
            <Head title="Investment Disclaimer" />
            
            <div className="min-h-screen bg-gray-50 dark:bg-gray-900 py-8">
                <div className="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
                    {/* Header */}
                    <div className="mb-8">
                        <Link
                            href={isAuthenticated ? "/dashboard" : "/"}
                            className="inline-flex items-center text-sm font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 mb-4"
                        >
                            <ChevronLeft className="mr-1 h-4 w-4" />
                            {isAuthenticated ? "Back to Dashboard" : "Back to Home"}
                        </Link>
                        
                        <div className="flex items-center gap-3 mb-4">
                            <Shield className="h-8 w-8 text-red-600 dark:text-red-400" />
                            <h1 className="text-3xl font-bold text-gray-900 dark:text-white">
                                Investment Application Disclaimer
                            </h1>
                        </div>
                        
                        <div className="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4">
                            <div className="flex items-center gap-2 mb-3">
                                <AlertTriangle className="h-5 w-5 text-red-600 dark:text-red-400 flex-shrink-0" />
                                <p className="text-sm font-medium text-red-800 dark:text-red-200">
                                    IMPORTANT NOTICE: THIS IS AN EDUCATIONAL TOOL ONLY
                                </p>
                            </div>
                            <p className="text-xs text-red-700 dark:text-red-300 font-medium">
                                Users must read and acknowledge this disclaimer before proceeding to use the application.
                            </p>
                        </div>
                    </div>

                    {/* Content */}
                    <div className="bg-white dark:bg-gray-800 shadow-lg rounded-lg">
                        <div className="p-8 space-y-8">
                            
                            {/* Not Financial Advice */}
                            <section>
                                <div className="flex items-center gap-2 mb-4">
                                    <FileText className="h-6 w-6 text-blue-600 dark:text-blue-400" />
                                    <h2 className="text-xl font-semibold text-gray-900 dark:text-white">
                                        NOT FINANCIAL ADVICE
                                    </h2>
                                </div>
                                <p className="text-gray-700 dark:text-gray-300 leading-relaxed">
                                    This investment screening application is designed purely as an educational and informational tool. 
                                    <span className="font-semibold text-red-600 dark:text-red-400"> IT IS NOT FINANCIAL ADVICE, INVESTMENT ADVICE, OR A RECOMMENDATION TO BUY OR SELL ANY SECURITIES.</span> 
                                    The information provided by this application should not be considered as professional financial guidance, 
                                    and you should not rely solely on this tool for making investment decisions.
                                </p>
                            </section>

                            {/* Financial Risk Warning */}
                            <section>
                                <div className="flex items-center gap-2 mb-4">
                                    <AlertTriangle className="h-6 w-6 text-red-600 dark:text-red-400" />
                                    <h2 className="text-xl font-semibold text-gray-900 dark:text-white">
                                        FINANCIAL RISK WARNING
                                    </h2>
                                </div>
                                <p className="text-gray-700 dark:text-gray-300 leading-relaxed">
                                    <span className="font-semibold text-red-600 dark:text-red-400">ALL INVESTMENTS CARRY SUBSTANTIAL RISK OF LOSS.</span> 
                                    Stock trading and investment activities can result in significant financial losses, including the complete loss of your invested capital. 
                                    Past performance is not indicative of future results. Market conditions can change rapidly and unpredictably.
                                </p>
                            </section>

                            {/* Due Diligence */}
                            <section>
                                <div className="flex items-center gap-2 mb-4">
                                    <Users className="h-6 w-6 text-green-600 dark:text-green-400" />
                                    <h2 className="text-xl font-semibold text-gray-900 dark:text-white">
                                        REQUIRED DUE DILIGENCE
                                    </h2>
                                </div>
                                <p className="text-gray-700 dark:text-gray-300 leading-relaxed mb-4">
                                    Before making any investment decisions, you must:
                                </p>
                                <ul className="list-disc pl-6 space-y-2 text-gray-700 dark:text-gray-300">
                                    <li><strong>Conduct your own comprehensive research</strong> on any securities you are considering</li>
                                    <li><strong>Analyze market sentiment</strong> through multiple independent sources</li>
                                    <li><strong>Review fundamental analysis</strong> including earnings reports, financial statements, and company news</li>
                                    <li><strong>Assess technical indicators</strong> beyond what this application provides</li>
                                    <li><strong>Consider overall market conditions</strong> and economic factors</li>
                                    <li><strong>Evaluate your personal risk tolerance</strong> and financial situation</li>
                                    <li><strong>Consult with qualified financial professionals</strong> before making significant investment decisions</li>
                                </ul>

                                <div className="mt-6 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4">
                                    <h3 className="text-lg font-semibold text-red-800 dark:text-red-200 mb-3">
                                        ⚠️ RED FLAGS: You probably should not invest in a security if very recently:
                                    </h3>
                                    <ul className="list-disc pl-6 space-y-2 text-red-700 dark:text-red-300">
                                        <li>The CEO or any board member has become a <strong>convicted felon</strong></li>
                                        <li>The CEO or any board member has been identified as using <strong>illicit drugs</strong> (cocaine, heroin, methamphetamine, fentanyl, LSD, MDMA/ecstasy, or other controlled substances)</li>
                                        <li>The CEO or any board member has been identified with any <strong>hate group</strong> or extremist organization</li>
                                        <li>The CEO has been caught in <strong>inappropriate situations with fellow employees</strong> (such as scandals at rock concerts or other public events)</li>
                                        <li>Management has conducted <strong>financial fraud</strong></li>
                                        <li>The company has <strong>misreported earnings</strong> or financial statements</li>
                                        <li>Leadership has engaged in any other <strong>socially unacceptable behavior</strong></li>
                                    </ul>
                                    <p className="text-sm text-red-600 dark:text-red-400 mt-3 font-medium">
                                        These are serious warning signs that may indicate poor governance, ethical issues, potential fraud, or poor public sentiment that could negatively impact your investment.
                                    </p>
                                </div>
                            </section>

                            {/* Risk Management */}
                            <section>
                                <div className="flex items-center gap-2 mb-4">
                                    <DollarSign className="h-6 w-6 text-yellow-600 dark:text-yellow-400" />
                                    <h2 className="text-xl font-semibold text-gray-900 dark:text-white">
                                        RISK MANAGEMENT REQUIREMENTS
                                    </h2>
                                </div>
                                <p className="text-gray-700 dark:text-gray-300 leading-relaxed mb-4">
                                    <span className="font-semibold">PROPER RISK MANAGEMENT IS CRITICAL.</span> You must implement appropriate risk management strategies:
                                </p>
                                
                                <div className="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg p-4 mb-4">
                                    <h3 className="font-semibold text-yellow-800 dark:text-yellow-200 mb-3">Stop Loss Guidelines</h3>
                                    <ul className="list-disc pl-6 space-y-2 text-yellow-700 dark:text-yellow-300">
                                        <li><strong>Initial Stop Loss:</strong> Consider setting a conservative stop loss (e.g., 1% below your entry price) to limit initial downside risk</li>
                                        <li><strong>Trailing Stop Loss:</strong> For positions showing gains above 4%, consider implementing a trailing stop loss (e.g., 2.5%) to protect profits while allowing for continued upside participation</li>
                                        <li><strong>Position Sizing:</strong> Never risk more than you can afford to lose on any single trade</li>
                                        <li><strong>Portfolio Allocation:</strong> Rather than investing in one stock or crypto, diversify your portfolio to invest in 5 to 8 securities which you can easily track and manage</li>
                                    </ul>
                                    <p className="text-xs font-medium text-yellow-600 dark:text-yellow-400 mt-3">
                                        These are general risk management concepts and NOT specific recommendations for your situation.
                                    </p>
                                </div>
                            </section>

                            {/* Final Warnings */}
                            <section className="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-6">
                                <h3 className="font-semibold text-red-800 dark:text-red-200 text-lg mb-4">FINAL WARNING</h3>
                                <p className="text-red-700 dark:text-red-300 leading-relaxed mb-4">
                                    <span className="font-semibold">NEVER INVEST MONEY YOU CANNOT AFFORD TO LOSE.</span> This application is a tool for educational purposes only. 
                                    The responsibility for all investment decisions rests entirely with you, the user.
                                </p>
                                <p className="text-sm text-red-600 dark:text-red-400">
                                    The creators, operators, and contributors to this application disclaim all liability for any losses or damages 
                                    resulting from use of this tool and make no warranties regarding the accuracy, completeness, or timeliness of information.
                                </p>
                            </section>

                            {/* Cookies & Privacy Compliance */}
                            <section>
                                <div className="flex items-center gap-2 mb-4">
                                    <Cookie className="h-6 w-6 text-orange-600 dark:text-orange-400" />
                                    <h2 className="text-xl font-semibold text-gray-900 dark:text-white">
                                        COOKIES & PRIVACY COMPLIANCE
                                    </h2>
                                </div>
                                
                                <div className="space-y-4">
                                    <div className="bg-orange-50 dark:bg-orange-900/20 border border-orange-200 dark:border-orange-800 rounded-lg p-4">
                                        <h3 className="font-semibold text-orange-800 dark:text-orange-200 mb-3">Cookie Usage</h3>
                                        <p className="text-orange-700 dark:text-orange-300 text-sm leading-relaxed mb-3">
                                            This application uses cookies and similar technologies to enhance your experience, 
                                            provide essential functionality, and analyze usage patterns. By using this application, 
                                            you consent to our use of cookies as described below.
                                        </p>
                                        
                                        <div className="space-y-3">
                                            <div>
                                                <h4 className="font-medium text-orange-800 dark:text-orange-200 text-sm">Essential Cookies</h4>
                                                <p className="text-orange-600 dark:text-orange-400 text-xs">
                                                    Required for authentication, session management, and core application functionality. 
                                                    These cannot be disabled without affecting site operation.
                                                </p>
                                            </div>
                                            
                                            <div>
                                                <h4 className="font-medium text-orange-800 dark:text-orange-200 text-sm">Functional Cookies</h4>
                                                <p className="text-orange-600 dark:text-orange-400 text-xs">
                                                    Store user preferences, settings, and disclaimer acknowledgments to improve your experience.
                                                </p>
                                            </div>
                                            
                                            <div>
                                                <h4 className="font-medium text-orange-800 dark:text-orange-200 text-sm">Analytics & Performance</h4>
                                                <p className="text-orange-600 dark:text-orange-400 text-xs">
                                                    Help us understand how the application is used to improve performance and user experience. 
                                                    Data is anonymized and aggregated.
                                                </p>
                                            </div>
                                        </div>
                                    </div>

                                    <div className="bg-orange-50 dark:bg-orange-900/20 border border-orange-200 dark:border-orange-800 rounded-lg p-4">
                                        <h3 className="font-semibold text-orange-800 dark:text-orange-200 mb-3">Data Processing & Privacy</h3>
                                        <ul className="list-disc pl-6 space-y-1 text-orange-700 dark:text-orange-300 text-sm">
                                            <li>We process personal data in accordance with applicable privacy laws (GDPR, CCPA, etc.)</li>
                                            <li>User data is stored securely and used only for legitimate business purposes</li>
                                            <li>You have rights to access, modify, or delete your personal information</li>
                                            <li>We do not sell personal data to third parties</li>
                                            <li>Data retention follows industry standards and regulatory requirements</li>
                                        </ul>
                                    </div>

                                    <div className="bg-orange-50 dark:bg-orange-900/20 border border-orange-200 dark:border-orange-800 rounded-lg p-4">
                                        <h3 className="font-semibold text-orange-800 dark:text-orange-200 mb-3">Third-Party Services</h3>
                                        <p className="text-orange-700 dark:text-orange-300 text-sm leading-relaxed mb-2">
                                            This application may integrate with third-party services for market data, analytics, 
                                            and functionality enhancement. These services may have their own privacy policies:
                                        </p>
                                        <ul className="list-disc pl-6 space-y-1 text-orange-600 dark:text-orange-400 text-xs">
                                            <li>Financial data providers for real-time market information</li>
                                            <li>Analytics services for application performance monitoring</li>
                                            <li>Authentication services for secure user access</li>
                                            <li>Content delivery networks for improved loading speeds</li>
                                        </ul>
                                    </div>

                                    <div className="bg-orange-50 dark:bg-orange-900/20 border border-orange-200 dark:border-orange-800 rounded-lg p-4">
                                        <h3 className="font-semibold text-orange-800 dark:text-orange-200 mb-3">Your Rights & Choices</h3>
                                        <ul className="list-disc pl-6 space-y-1 text-orange-700 dark:text-orange-300 text-sm">
                                            <li><strong>Cookie Control:</strong> Manage cookies through your browser settings</li>
                                            <li><strong>Data Access:</strong> Request a copy of your personal data</li>
                                            <li><strong>Data Portability:</strong> Request transfer of your data to another service</li>
                                            <li><strong>Right to Deletion:</strong> Request removal of your personal information</li>
                                            <li><strong>Opt-Out:</strong> Withdraw consent for non-essential data processing</li>
                                        </ul>
                                        <p className="text-orange-600 dark:text-orange-400 text-xs mt-2">
                                            To exercise these rights, contact us through the support channels available in the application.
                                        </p>
                                    </div>
                                </div>
                            </section>

                            {/* Acknowledgment Section - Only show if not already accepted */}
                            {!hasAcceptedDisclaimer && (
                                <section className="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-6">
                                    <h3 className="font-semibold text-blue-800 dark:text-blue-200 text-lg mb-4">REQUIRED ACKNOWLEDGMENT</h3>
                                    <p className="text-blue-700 dark:text-blue-300 leading-relaxed mb-6">
                                        By checking the box below, you acknowledge that you have read, understood, and agree to be bound by all terms 
                                        and conditions outlined in this disclaimer. You confirm that you understand the risks involved in stock trading 
                                        and investment activities, and that you will not rely solely on this application for making investment decisions.
                                    </p>
                                    
                                    <div className="space-y-4">
                                        <label className="flex items-start gap-3 cursor-pointer">
                                            <input
                                                type="checkbox"
                                                checked={acknowledged}
                                                onChange={(e) => setAcknowledged(e.target.checked)}
                                                className="mt-1 h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
                                            />
                                            <span className="text-sm text-blue-700 dark:text-blue-300 leading-relaxed">
                                                I have read and understand this disclaimer in its entirety. I acknowledge that this is an educational tool only, 
                                                not financial advice, and that all investment decisions are made at my own risk. I understand the importance of 
                                                implementing proper risk management strategies and consulting with qualified financial professionals.
                                            </span>
                                        </label>
                                        
                                        <label className="flex items-start gap-3 cursor-pointer">
                                            <input
                                                type="checkbox"
                                                checked={cookiesAcknowledged}
                                                onChange={(e) => setCookiesAcknowledged(e.target.checked)}
                                                className="mt-1 h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
                                            />
                                            <span className="text-sm text-blue-700 dark:text-blue-300 leading-relaxed">
                                                I consent to the use of cookies and data processing as described in the privacy section above. 
                                                I understand my rights regarding data privacy and how to exercise them. I acknowledge that essential cookies 
                                                are necessary for the application to function properly.
                                            </span>
                                        </label>
                                        
                                        <div className="flex gap-3">
                                            <button
                                                onClick={handleAcknowledge}
                                                disabled={!acknowledged || !cookiesAcknowledged || processing}
                                                className={`px-6 py-3 rounded-lg font-medium transition-all ${
                                                    acknowledged && cookiesAcknowledged && !processing
                                                        ? 'bg-blue-600 hover:bg-blue-700 text-white cursor-pointer'
                                                        : 'bg-gray-300 dark:bg-gray-600 text-gray-500 dark:text-gray-400 cursor-not-allowed'
                                                }`}
                                            >
                                                <div className="flex items-center gap-2">
                                                    <Check className="h-4 w-4" />
                                                    {processing ? 'Processing...' : 'Acknowledge All and Proceed'}
                                                </div>
                                            </button>
                                            
                                            <Link
                                                href="/"
                                                className="px-6 py-3 rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 font-medium transition-all"
                                            >
                                                Return to Home
                                            </Link>
                                        </div>
                                    </div>
                                </section>
                            )}

                            {/* Last Updated */}
                            <div className="text-center pt-6 border-t border-gray-200 dark:border-gray-700">
                                <p className="text-xs text-gray-500 dark:text-gray-400">
                                    This disclaimer was last updated on November 30, 2025. Securities regulations and market conditions change frequently. 
                                    Always verify current regulations and consult current professional advice.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>


        </>
    );
}