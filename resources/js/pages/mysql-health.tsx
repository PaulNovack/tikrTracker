import { Head } from '@inertiajs/react'
import AppLayout from '@/layouts/app-layout'
import { Button } from '@/components/ui/button'
import { type BreadcrumbItem } from '@/types'
import { Fragment, useEffect, useState } from 'react'

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'System', href: '/mysql-health' },
    {
        title: 'MySQL Health',
        href: '/mysql-health',
    },
];

interface MySqlHealthProps {
    metrics: {
        system: {
            uptime_seconds: number
            uptime_hours: number
            uptime_formatted: string
        }
        connections: {
            current: number
            running: number
            max_used: number
            max_allowed: number
            usage_percent: number
            aborted: number
        }
        performance: {
            slow_queries: number
            total_queries: number
            buffer_pool_dirty_pages: number
            buffer_pool_total_pages: number
            buffer_pool_efficiency: number
        }
        storage: {
            database_size_mb: number
            database_size_gb: number
        }
        processes: {
            total: number
            active: number
            list: Array<{
                Id: number
                User: string
                Host: string
                db: string | null
                Command: string
                Time: number
                State: string
                Info: string | null
            }>
        }
        health: {
            overall_status: string
            overall_score: number
            indicators: {
                connections: { status: string; score: number; message: string }
                performance: { status: string; score: number; message: string }
                buffer: { status: string; score: number; message: string }
                stability: { status: string; score: number; message: string }
            }
        }
        error?: string
    }
    lastUpdated: string
}

export default function MySqlHealth({ metrics: initialMetrics, lastUpdated: initialLastUpdated }: MySqlHealthProps) {
    const [metrics, setMetrics] = useState(initialMetrics)
    const [lastUpdated, setLastUpdated] = useState(initialLastUpdated)
    const [autoRefresh, setAutoRefresh] = useState(true)
    const [isLoading, setIsLoading] = useState(false)
    const [expandedProcessId, setExpandedProcessId] = useState<number | null>(null)
    const [killingProcessId, setKillingProcessId] = useState<number | null>(null)

    const refreshMetrics = async () => {
        if (isLoading) return
        
        setIsLoading(true)
        try {
            const response = await fetch('/api/mysql-health')
            const data = await response.json()
            setMetrics(data.metrics)
            setLastUpdated(data.lastUpdated)
        } catch (error) {
            console.error('Failed to refresh metrics:', error)
        } finally {
            setIsLoading(false)
        }
    }

    const toggleProcess = (processId: number) => {
        setExpandedProcessId((currentId) => (currentId === processId ? null : processId))
    }

    const killQuery = async (processId: number) => {
        const confirmed = window.confirm(`Kill MySQL query ${processId}?`)

        if (!confirmed) return

        setKillingProcessId(processId)
        try {
            const response = await fetch('/api/mysql-health/kill-query', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
                },
                body: JSON.stringify({ pid: processId }),
            })

            const result = await response.json()

            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Failed to kill query')
            }

            await refreshMetrics()
        } catch (error) {
            console.error('Error killing MySQL query:', error)
            window.alert('Failed to kill the query. Check the console or server logs for details.')
        } finally {
            setKillingProcessId(null)
        }
    }

    useEffect(() => {
        if (!autoRefresh) return

        const interval = setInterval(refreshMetrics, 30000) // Refresh every 30 seconds
        return () => clearInterval(interval)
    }, [autoRefresh, isLoading])

    const getStatusColor = (status: string) => {
        switch (status) {
            case 'excellent': return 'text-green-600 bg-green-50 border-green-200'
            case 'good': return 'text-blue-600 bg-blue-50 border-blue-200'
            case 'warning': return 'text-yellow-600 bg-yellow-50 border-yellow-200'
            case 'critical': return 'text-red-600 bg-red-50 border-red-200'
            default: return 'text-gray-600 bg-gray-50 border-gray-200'
        }
    }

    const getStatusIcon = (status: string) => {
        switch (status) {
            case 'excellent': return '🟢'
            case 'good': return '🔵'
            case 'warning': return '🟡'
            case 'critical': return '🔴'
            default: return '⚪'
        }
    }

    if (metrics.error) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="MySQL Health Monitor" />
                <div className="p-6">
                    <div className="max-w-7xl mx-auto">
                        <div className="bg-red-50 border border-red-200 rounded-lg p-4">
                            <h2 className="text-lg font-semibold text-red-800 mb-2">Error Loading MySQL Health Data</h2>
                            <p className="text-red-700">{metrics.error}</p>
                        </div>
                    </div>
                </div>
            </AppLayout>
        )
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="MySQL Health Monitor" />
            
            <div className="p-6">
                <div className="max-w-7xl mx-auto">
                    {/* Header */}
                    <div className="mb-6 flex justify-between items-center">
                        <div>
                            <h1 className="text-2xl font-bold text-gray-900 dark:text-white">
                                MySQL Health Monitor
                            </h1>
                            <p className="text-sm text-gray-600 dark:text-gray-400 mt-1">
                                Last updated: {lastUpdated}
                            </p>
                        </div>
                        <div className="flex gap-2">
                            <label className="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                                <input
                                    type="checkbox"
                                    checked={autoRefresh}
                                    onChange={(e) => setAutoRefresh(e.target.checked)}
                                    className="rounded"
                                />
                                Auto-refresh (30s)
                            </label>
                            <Button
                                onClick={refreshMetrics}
                                disabled={isLoading}
                                variant="outline"
                                size="sm"
                            >
                                {isLoading ? 'Refreshing...' : 'Refresh Now'}
                            </Button>
                        </div>
                    </div>

                    {/* Overall Health Status */}
                    <div className={`mb-6 p-4 rounded-lg border-2 ${getStatusColor(metrics.health.overall_status)}`}>
                        <div className="flex items-center gap-3">
                            <span className="text-2xl">{getStatusIcon(metrics.health.overall_status)}</span>
                            <div>
                                <h2 className="text-lg font-semibold">
                                    Overall Health: {metrics.health.overall_status.toUpperCase()} ({metrics.health.overall_score}%)
                                </h2>
                                <p className="text-sm opacity-75">
                                    MySQL server is {metrics.health.overall_status === 'excellent' ? 'running optimally' : 
                                                    metrics.health.overall_status === 'good' ? 'performing well' :
                                                    metrics.health.overall_status === 'warning' ? 'showing some issues' : 'experiencing problems'}
                                </p>
                            </div>
                        </div>
                    </div>

                    {/* Key Metrics Grid */}
                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
                        {/* System Uptime */}
                        <div className="bg-white dark:bg-gray-800 p-4 rounded-lg shadow">
                            <h3 className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-2">System Uptime</h3>
                            <div className="text-2xl font-bold text-gray-900 dark:text-white">
                                {metrics.system.uptime_formatted}
                            </div>
                            <div className="text-sm text-gray-600 dark:text-gray-400">
                                {metrics.system.uptime_hours}h total
                            </div>
                        </div>

                        {/* Connection Usage */}
                        <div className="bg-white dark:bg-gray-800 p-4 rounded-lg shadow">
                            <h3 className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-2">Connection Usage</h3>
                            <div className="text-2xl font-bold text-gray-900 dark:text-white">
                                {metrics.connections.usage_percent}%
                            </div>
                            <div className="text-sm text-gray-600 dark:text-gray-400">
                                {metrics.connections.current}/{metrics.connections.max_allowed} connections
                            </div>
                        </div>

                        {/* Query Performance */}
                        <div className="bg-white dark:bg-gray-800 p-4 rounded-lg shadow">
                            <h3 className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-2">Query Performance</h3>
                            <div className="text-2xl font-bold text-gray-900 dark:text-white">
                                {metrics.performance.slow_queries}
                            </div>
                            <div className="text-sm text-gray-600 dark:text-gray-400">
                                slow queries
                            </div>
                        </div>

                        {/* Database Size */}
                        <div className="bg-white dark:bg-gray-800 p-4 rounded-lg shadow">
                            <h3 className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-2">Database Size</h3>
                            <div className="text-2xl font-bold text-gray-900 dark:text-white">
                                {metrics.storage.database_size_gb} GB
                            </div>
                            <div className="text-sm text-gray-600 dark:text-gray-400">
                                {metrics.storage.database_size_mb} MB total
                            </div>
                        </div>
                    </div>

                    {/* Health Indicators */}
                    <div className="bg-white dark:bg-gray-800 rounded-lg shadow mb-6 overflow-hidden">
                        <div className="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                            <h3 className="text-lg font-semibold text-gray-900 dark:text-white">Health Indicators</h3>
                        </div>
                        <div className="p-6">
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                {Object.entries(metrics.health.indicators).map(([key, indicator]) => (
                                    <div key={key} className={`p-3 rounded-lg border ${getStatusColor(indicator.status)}`}>
                                        <div className="flex items-center gap-2 mb-1">
                                            <span>{getStatusIcon(indicator.status)}</span>
                                            <span className="font-medium capitalize">{key}</span>
                                            <span className="text-sm">({indicator.score}/10)</span>
                                        </div>
                                        <div className="text-sm opacity-75">{indicator.message}</div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>

                    {/* Detailed Metrics */}
                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        {/* Connection Details */}
                        <div className="bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden">
                            <div className="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                                <h3 className="text-lg font-semibold text-gray-900 dark:text-white">Connection Details</h3>
                            </div>
                            <div className="p-6 space-y-3">
                                <div className="flex justify-between">
                                    <span className="text-gray-600 dark:text-gray-400">Current Connections:</span>
                                    <span className="font-medium text-gray-900 dark:text-white">{metrics.connections.current}</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-gray-600 dark:text-gray-400">Running Threads:</span>
                                    <span className="font-medium text-gray-900 dark:text-white">{metrics.connections.running}</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-gray-600 dark:text-gray-400">Max Used:</span>
                                    <span className="font-medium text-gray-900 dark:text-white">{metrics.connections.max_used}</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-gray-600 dark:text-gray-400">Max Allowed:</span>
                                    <span className="font-medium text-gray-900 dark:text-white">{metrics.connections.max_allowed}</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-gray-600 dark:text-gray-400">Aborted Connects:</span>
                                    <span className="font-medium text-gray-900 dark:text-white">{metrics.connections.aborted}</span>
                                </div>
                            </div>
                        </div>

                        {/* Performance Details */}
                        <div className="bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden">
                            <div className="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                                <h3 className="text-lg font-semibold text-gray-900 dark:text-white">Performance Details</h3>
                            </div>
                            <div className="p-6 space-y-3">
                                <div className="flex justify-between">
                                    <span className="text-gray-600 dark:text-gray-400">Slow Queries:</span>
                                    <span className="font-medium text-gray-900 dark:text-white">{metrics.performance.slow_queries}</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-gray-600 dark:text-gray-400">Total Queries:</span>
                                    <span className="font-medium text-gray-900 dark:text-white">{metrics.performance.total_queries.toLocaleString()}</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-gray-600 dark:text-gray-400">Buffer Pool Efficiency:</span>
                                    <span className="font-medium text-gray-900 dark:text-white">{metrics.performance.buffer_pool_efficiency}%</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-gray-600 dark:text-gray-400">Dirty Pages:</span>
                                    <span className="font-medium text-gray-900 dark:text-white">{metrics.performance.buffer_pool_dirty_pages.toLocaleString()}</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-gray-600 dark:text-gray-400">Total Pages:</span>
                                    <span className="font-medium text-gray-900 dark:text-white">{metrics.performance.buffer_pool_total_pages.toLocaleString()}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Active Processes */}
                    {metrics.processes.active > 0 && (
                        <div className="mt-6 bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden">
                            <div className="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                                <h3 className="text-lg font-semibold text-gray-900 dark:text-white">
                                    Active Processes ({metrics.processes.active}/{metrics.processes.total})
                                </h3>
                            </div>
                            <div className="overflow-x-auto">
                                <table className="w-full">
                                    <thead className="bg-gray-50 dark:bg-gray-700">
                                        <tr>
                                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Action</th>
                                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">ID</th>
                                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">User</th>
                                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Database</th>
                                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Command</th>
                                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Time</th>
                                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">State</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                                        {metrics.processes.list.map((process) => {
                                            const isExpanded = expandedProcessId === process.Id

                                            return (
                                                <Fragment key={process.Id}>
                                                    <tr
                                                        className="cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700/50"
                                                        onClick={() => toggleProcess(process.Id)}
                                                    >
                                                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                                            <div className="flex items-center gap-2">
                                                                <Button
                                                                    type="button"
                                                                    onClick={(event) => {
                                                                        event.stopPropagation()
                                                                        toggleProcess(process.Id)
                                                                    }}
                                                                    variant="outline"
                                                                    size="sm"
                                                                >
                                                                    {isExpanded ? 'Hide' : 'Show'}
                                                                </Button>
                                                                <Button
                                                                    type="button"
                                                                    onClick={(event) => {
                                                                        event.stopPropagation()
                                                                        void killQuery(process.Id)
                                                                    }}
                                                                    disabled={killingProcessId === process.Id}
                                                                    variant="destructive"
                                                                    size="sm"
                                                                >
                                                                    {killingProcessId === process.Id ? 'Killing...' : 'Kill'}
                                                                </Button>
                                                            </div>
                                                        </td>
                                                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">{process.Id}</td>
                                                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">{process.User}</td>
                                                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">{process.db || '-'}</td>
                                                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">{process.Command}</td>
                                                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">{process.Time}s</td>
                                                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">{process.State || '-'}</td>
                                                    </tr>
                                                    {isExpanded && (
                                                        <tr className="bg-gray-50 dark:bg-gray-900/30">
                                                            <td className="px-6 py-4 text-sm text-gray-900 dark:text-white" colSpan={7}>
                                                                <div className="space-y-3">
                                                                    <div className="flex items-center justify-between gap-4">
                                                                        <div className="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                                                            Full SQL
                                                                        </div>
                                                                        <div className="text-xs text-gray-500 dark:text-gray-400">
                                                                            Host: {process.Host}
                                                                        </div>
                                                                    </div>
                                                                    <pre className="whitespace-pre-wrap break-words rounded-lg bg-white p-4 text-xs leading-6 text-gray-900 shadow-sm dark:bg-gray-800 dark:text-gray-100">
                                                                        {process.Info || '-'}
                                                                    </pre>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    )}
                                                </Fragment>
                                            )
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    )
}