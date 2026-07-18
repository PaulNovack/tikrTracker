import { Shield, AlertTriangle, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { router } from '@inertiajs/react';

export default function DisclaimerNotice() {
    const [showDeleteConfirm, setShowDeleteConfirm] = useState(false);
    const [confirmationText, setConfirmationText] = useState('');
    const [confirmed, setConfirmed] = useState(false);

    const handleDeleteData = () => {
        if (confirmed && confirmationText === 'DELETE MY DATA') {
            router.post('/disclaimer/delete-data', {
                confirm_deletion: true,
                confirmation_text: confirmationText,
            }, {
                onSuccess: () => {
                    setShowDeleteConfirm(false);
                    setConfirmationText('');
                    setConfirmed(false);
                },
                onError: (errors) => {
                    console.error('Data deletion failed:', errors);
                }
            });
        }
    };

    return (
        <div className="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg p-6 mb-6">
            <div className="flex items-start gap-4">
                <div className="flex-shrink-0">
                    <Shield className="h-6 w-6 text-amber-600 dark:text-amber-400" />
                </div>
                
                <div className="flex-1">
                    <h3 className="text-lg font-semibold text-amber-800 dark:text-amber-200 mb-2">
                        IMPORTANT NOTICE: THIS IS AN EDUCATIONAL TOOL ONLY
                    </h3>
                    
                    <p className="text-amber-700 dark:text-amber-300 text-sm mb-4">
                        Users must read and acknowledge this disclaimer before proceeding to use the application.
                        You have already accepted our terms and conditions.
                    </p>
                    
                    <div className="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-md p-3 mb-4">
                        <p className="text-red-800 dark:text-red-200 text-sm font-medium">
                            ⚠️ Data Deletion Option Available
                        </p>
                        <p className="text-red-700 dark:text-red-300 text-sm mt-1">
                            You have the option of deleting all your personal data from this website. 
                            <strong> This action cannot be undone and we cannot restore this data if you choose to delete it.</strong>
                            {' '}Proceed with caution.
                        </p>
                    </div>
                    
                    <div className="flex items-center gap-4">
                        <button
                            onClick={() => setShowDeleteConfirm(true)}
                            className="inline-flex items-center gap-2 px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-md transition-colors"
                        >
                            <Trash2 className="h-4 w-4" />
                            Delete My Personal Data
                        </button>
                        
                        <a
                            href="/disclaimer"
                            className="text-amber-600 dark:text-amber-400 hover:text-amber-800 dark:hover:text-amber-200 text-sm font-medium underline"
                        >
                            Review Disclaimer
                        </a>
                    </div>
                </div>
            </div>

            {/* Deletion Confirmation Modal */}
            {showDeleteConfirm && (
                <div className="fixed inset-0 bg-black/50 flex items-center justify-center p-4 z-50">
                    <div className="bg-white dark:bg-gray-800 rounded-lg p-6 max-w-md w-full">
                        <div className="flex items-center gap-3 mb-4">
                            <AlertTriangle className="h-6 w-6 text-red-600 dark:text-red-400" />
                            <h3 className="text-lg font-bold text-gray-900 dark:text-white">
                                Delete Personal Data
                            </h3>
                        </div>
                        
                        <div className="space-y-4">
                            <div className="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4">
                                <p className="text-red-800 dark:text-red-200 text-sm font-medium mb-2">
                                    ⚠️ WARNING: This action cannot be undone!
                                </p>
                                <p className="text-red-700 dark:text-red-300 text-sm">
                                    This will permanently delete all your personal data from our systems, including:
                                </p>
                                <ul className="text-red-700 dark:text-red-300 text-sm mt-2 ml-4 list-disc">
                                    <li>Your disclaimer acceptance record</li>
                                    <li>Your IP address and browser information</li>
                                    <li>All tracking and visit history</li>
                                </ul>
                            </div>

                            <div>
                                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    To confirm deletion, type: <span className="font-mono bg-gray-100 dark:bg-gray-700 px-1 rounded">DELETE MY DATA</span>
                                </label>
                                <input
                                    type="text"
                                    value={confirmationText}
                                    onChange={(e) => setConfirmationText(e.target.value)}
                                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-red-500 focus:border-red-500"
                                    placeholder="Type the confirmation text"
                                />
                            </div>

                            <div className="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    id="confirm-deletion"
                                    checked={confirmed}
                                    onChange={(e) => setConfirmed(e.target.checked)}
                                    className="h-4 w-4 text-red-600 focus:ring-red-500 border-gray-300 rounded"
                                />
                                <label htmlFor="confirm-deletion" className="text-sm text-gray-700 dark:text-gray-300">
                                    I understand this action is permanent and cannot be reversed
                                </label>
                            </div>

                            <div className="flex gap-3 pt-4">
                                <button
                                    onClick={handleDeleteData}
                                    disabled={!confirmed || confirmationText !== 'DELETE MY DATA'}
                                    className="flex-1 px-4 py-2 bg-red-600 hover:bg-red-700 disabled:bg-gray-300 disabled:cursor-not-allowed text-white font-medium rounded-md transition-colors"
                                >
                                    Delete My Data
                                </button>
                                <button
                                    onClick={() => {
                                        setShowDeleteConfirm(false);
                                        setConfirmationText('');
                                        setConfirmed(false);
                                    }}
                                    className="flex-1 px-4 py-2 bg-gray-200 hover:bg-gray-300 dark:bg-gray-600 dark:hover:bg-gray-500 text-gray-800 dark:text-white font-medium rounded-md transition-colors"
                                >
                                    Cancel
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}