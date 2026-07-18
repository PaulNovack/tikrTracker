import { Clock, Eye } from 'lucide-react';
import { useVisitTracker } from '@/hooks/useVisitTracker';

interface VisitStatusIndicatorProps {
    className?: string;
    showDebugInfo?: boolean;
}

export default function VisitStatusIndicator({ 
    className = "", 
    showDebugInfo = false 
}: VisitStatusIndicatorProps) {
    const { status } = useVisitTracker();

    // Don't show if disclaimer should be triggered
    if (status.shouldTrigger) {
        return null;
    }

    // Only show debug info in development
    if (!showDebugInfo && process.env.NODE_ENV === 'production') {
        return null;
    }

    return (
        <div className={`fixed bottom-4 right-4 bg-white dark:bg-gray-800 shadow-lg rounded-lg border border-gray-200 dark:border-gray-700 p-3 ${className}`}>
            <div className="text-xs font-medium text-gray-700 dark:text-gray-200 mb-2">
                Visit Status (Debug)
            </div>
            
            <div className="flex items-center gap-3 text-sm">
                <div className="flex items-center gap-1">
                    <Clock className="h-3 w-3 text-blue-600 dark:text-blue-400" />
                    <span className="text-gray-600 dark:text-gray-300">
                        {status.timeRemaining}s
                    </span>
                </div>
                
                <div className="flex items-center gap-1">
                    <Eye className="h-3 w-3 text-green-600 dark:text-green-400" />
                    <span className="text-gray-600 dark:text-gray-300">
                        {status.visitsRemaining} left
                    </span>
                </div>
            </div>
        </div>
    );
}