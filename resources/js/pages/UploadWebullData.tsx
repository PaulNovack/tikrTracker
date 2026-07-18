import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import Heading from '@/components/heading';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import { Upload, FileText, AlertCircle, CheckCircle2 } from 'lucide-react';
import { useState } from 'react';

interface UploadResult {
    success: boolean;
    message: string;
    details?: {
        processed: number;
        skipped: number;
        errors: number;
        error_details?: string[];
    };
}

export default function UploadWebullData() {
    const [selectedFile, setSelectedFile] = useState<File | null>(null);
    const [uploading, setUploading] = useState(false);
    const [uploadResult, setUploadResult] = useState<UploadResult | null>(null);

    const handleFileSelect = (event: React.ChangeEvent<HTMLInputElement>) => {
        const file = event.target.files?.[0];
        if (file) {
            setSelectedFile(file);
            setUploadResult(null);
        }
    };

    const handleUpload = async () => {
        if (!selectedFile) return;

        setUploading(true);
        setUploadResult(null);

        try {
            const formData = new FormData();
            formData.append('file', selectedFile);

            // Get CSRF token
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            
            const response = await fetch('/upload-webull-data', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-CSRF-TOKEN': csrfToken || '',
                    'Accept': 'application/json',
                },
            });

            const result = await response.json();
            
            setUploadResult(result);
            
            if (result.success) {
                setSelectedFile(null);
                // Reset file input
                const fileInput = document.getElementById('file-input') as HTMLInputElement;
                if (fileInput) fileInput.value = '';
            }
            
        } catch (error) {
            console.error('Upload error:', error);
            setUploadResult({
                success: false,
                message: 'Network error occurred during upload',
            });
        } finally {
            setUploading(false);
        }
    };

    const formatFileSize = (bytes: number) => {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    };

    return (
        <>
            <Head title="Upload Data - Webull" />
            <AppLayout
                breadcrumbs={[
                    { title: 'Webull', href: '/upload-webull-data' },
                    { title: 'Upload Data', href: '/upload-webull-data' }
                ]}
            >
                <div className="mx-auto max-w-4xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                    <Heading
                        title="Upload Data"
                        description="Upload and process Webull data files for analysis and integration into the system."
                    />

                    {/* Upload Card */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Upload className="h-5 w-5" />
                                File Upload
                            </CardTitle>
                            <CardDescription>
                                Select a Webull Orders Records CSV file to upload. The system will automatically parse and import your trading data.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="space-y-2">
                                <Label htmlFor="file-input">Select Webull CSV File</Label>
                                <Input
                                    id="file-input"
                                    type="file"
                                    accept=".csv"
                                    onChange={handleFileSelect}
                                    className="cursor-pointer"
                                />
                                <p className="text-sm text-muted-foreground">
                                    Maximum file size: 50MB. Only CSV files are accepted.
                                </p>
                            </div>

                            {selectedFile && (
                                <div className="rounded-lg border border-muted bg-muted/50 p-4">
                                    <div className="flex items-center gap-3">
                                        <FileText className="h-5 w-5 text-muted-foreground" />
                                        <div className="flex-1">
                                            <p className="font-medium">{selectedFile.name}</p>
                                            <p className="text-sm text-muted-foreground">
                                                {formatFileSize(selectedFile.size)} • {selectedFile.type || 'CSV file'}
                                            </p>
                                        </div>
                                        <Button
                                            onClick={handleUpload}
                                            disabled={uploading}
                                            className="ml-auto"
                                        >
                                            {uploading ? 'Processing...' : 'Upload & Process'}
                                        </Button>
                                    </div>
                                </div>
                            )}

                            {/* Upload Result */}
                            {uploadResult && (
                                <div className={`rounded-lg border p-4 ${
                                    uploadResult.success 
                                        ? 'border-green-200 bg-green-50 dark:border-green-800 dark:bg-green-950'
                                        : 'border-red-200 bg-red-50 dark:border-red-800 dark:bg-red-950'
                                }`}>
                                    <div className="flex items-start gap-3">
                                        {uploadResult.success ? (
                                            <CheckCircle2 className="h-5 w-5 text-green-600 dark:text-green-400" />
                                        ) : (
                                            <AlertCircle className="h-5 w-5 text-red-600 dark:text-red-400" />
                                        )}
                                        <div className="flex-1">
                                            <p className={`font-medium ${
                                                uploadResult.success
                                                    ? 'text-green-900 dark:text-green-100'
                                                    : 'text-red-900 dark:text-red-100'
                                            }`}>
                                                {uploadResult.message}
                                            </p>
                                            {uploadResult.details && (
                                                <div className={`mt-2 text-sm ${
                                                    uploadResult.success
                                                        ? 'text-green-700 dark:text-green-300'
                                                        : 'text-red-700 dark:text-red-300'
                                                }`}>
                                                    <div className="grid grid-cols-3 gap-2">
                                                        <div>✅ Processed: <strong>{uploadResult.details.processed}</strong></div>
                                                        <div>⏭️ Skipped: <strong>{uploadResult.details.skipped}</strong></div>
                                                        <div>❌ Errors: <strong>{uploadResult.details.errors}</strong></div>
                                                    </div>
                                                    {uploadResult.details.error_details && uploadResult.details.error_details.length > 0 && (
                                                        <details className="mt-2">
                                                            <summary className="cursor-pointer font-medium">Error Details</summary>
                                                            <ul className="mt-1 list-disc list-inside space-y-1">
                                                                {uploadResult.details.error_details.map((error, index) => (
                                                                    <li key={index} className="text-xs">{error}</li>
                                                                ))}
                                                            </ul>
                                                        </details>
                                                    )}
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Instructions */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Upload Instructions</CardTitle>
                            <CardDescription>
                                How to export and upload your Webull trading data
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="space-y-3">
                                <h4 className="font-medium text-sm">Step 1: Export from Webull</h4>
                                <ol className="list-decimal list-inside space-y-2 text-sm pl-4">
                                    <li>Log into your Webull account</li>
                                    <li>Go to Portfolio → Orders History</li>
                                    <li>Set your desired date range</li>
                                    <li>Click the export button and select "CSV" format</li>
                                    <li>Save the file as "Webull_Orders_Records.csv"</li>
                                </ol>
                            </div>
                            
                            <div className="space-y-3">
                                <h4 className="font-medium text-sm">Step 2: Upload & Process</h4>
                                <ol className="list-decimal list-inside space-y-2 text-sm pl-4">
                                    <li>Select your exported CSV file using the upload button above</li>
                                    <li>Click "Upload & Process" to import the data</li>
                                    <li>Review the processing results</li>
                                    <li>Check your Stock Transactions page to see imported data</li>
                                </ol>
                            </div>
                            
                            <div className="mt-4 p-4 rounded-lg bg-blue-50 dark:bg-blue-950 border border-blue-200 dark:border-blue-800">
                                <div className="space-y-2">
                                    <p className="text-sm text-blue-800 dark:text-blue-200 font-medium">
                                        🔄 Duplicate Prevention
                                    </p>
                                    <p className="text-sm text-blue-700 dark:text-blue-300">
                                        The system automatically detects and skips duplicate transactions based on symbol, type, timing, quantity, and price. 
                                        You can safely re-upload the same file multiple times.
                                    </p>
                                </div>
                            </div>

                            <div className="mt-4 p-4 rounded-lg bg-amber-50 dark:bg-amber-950 border border-amber-200 dark:border-amber-800">
                                <div className="space-y-2">
                                    <p className="text-sm text-amber-800 dark:text-amber-200 font-medium">
                                        📋 Supported Data
                                    </p>
                                    <ul className="text-sm text-amber-700 dark:text-amber-300 space-y-1">
                                        <li>• Only <strong>Filled</strong> orders are imported (Cancelled/Failed orders are skipped)</li>
                                        <li>• Supports both Buy and Sell transactions</li>
                                        <li>• Includes order timing, company names, and execution details</li>
                                        <li>• All major order types: Day, GTC, IOC, FOK</li>
                                    </ul>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </AppLayout>
        </>
    );
}