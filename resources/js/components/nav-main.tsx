import { Badge } from '@/components/ui/badge';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
} from '@/components/ui/sidebar';
import { resolveUrl } from '@/lib/utils';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';

export function NavMain({
    items = [],
    notificationCounts,
}: {
    items: NavItem[];
    notificationCounts?: { read: number; unread: number };
}) {
    const page = usePage();

    return (
        <SidebarGroup className="px-2 py-0">
            <SidebarGroupLabel>Platform</SidebarGroupLabel>
            <SidebarMenu>
                {items.map((item) => {
                    const isActive = page.url.startsWith(resolveUrl(item.href));
                    const hasItems = item.items && item.items.length > 0;
                    const isDisabled = item.disabled ?? false;
                    const isNotificationsPage =
                        item.title === 'View Notifications' &&
                        notificationCounts;

                    if (hasItems) {
                        return (
                            <Collapsible
                                key={item.title}
                                className="group/collapsible"
                                disabled={isDisabled}
                            >
                                <SidebarMenuItem>
                                    <CollapsibleTrigger asChild>
                                        <SidebarMenuButton
                                            tooltip={{ children: item.title }}
                                            disabled={isDisabled}
                                            className={
                                                isDisabled
                                                    ? 'cursor-not-allowed opacity-50'
                                                    : ''
                                            }
                                        >
                                            {item.icon && <item.icon />}
                                            <span>{item.title}</span>
                                            <ChevronRight className="ml-auto transition-transform group-data-[state=open]/collapsible:rotate-90" />
                                        </SidebarMenuButton>
                                    </CollapsibleTrigger>
                                    <CollapsibleContent>
                                        <SidebarMenuSub>
                                            {item.items?.map((subItem) => (
                                                <SidebarMenuSubItem
                                                    key={subItem.title}
                                                >
                                                    <SidebarMenuSubButton
                                                        asChild
                                                        isActive={page.url.startsWith(
                                                            resolveUrl(
                                                                subItem.href,
                                                            ),
                                                        )}
                                                    >
                                                        {subItem.openInNewTab ? (
                                                            <a
                                                                href={typeof subItem.href === 'string' ? subItem.href : subItem.href.url}
                                                                target="_blank"
                                                                rel="noopener noreferrer"
                                                            >
                                                                <span>
                                                                    {subItem.title}
                                                                </span>
                                                            </a>
                                                        ) : (
                                                            <Link
                                                                href={subItem.href}
                                                                prefetch
                                                            >
                                                                <span>
                                                                    {subItem.title}
                                                                </span>
                                                            </Link>
                                                        )}
                                                    </SidebarMenuSubButton>
                                                </SidebarMenuSubItem>
                                            ))}
                                        </SidebarMenuSub>
                                    </CollapsibleContent>
                                </SidebarMenuItem>
                            </Collapsible>
                        );
                    }

                    return (
                        <SidebarMenuItem key={item.title}>
                            {isDisabled ? (
                                <SidebarMenuButton
                                    disabled
                                    tooltip={{
                                        children: `${item.title} (Available for Traders and Admins only)`,
                                    }}
                                    className="cursor-not-allowed opacity-50"
                                >
                                    {item.icon && <item.icon />}
                                    <span>{item.title}</span>
                                </SidebarMenuButton>
                            ) : (
                                <SidebarMenuButton
                                    asChild
                                    isActive={isActive}
                                    tooltip={{ children: item.title }}
                                    className="flex items-center justify-between"
                                >
                                    {item.openInNewTab ? (
                                        <a
                                            href={typeof item.href === 'string' ? item.href : item.href.url}
                                            className="flex w-full items-center justify-between"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >
                                            <span className="flex items-center gap-2">
                                                {item.icon && <item.icon />}
                                                <span>{item.title}</span>
                                            </span>
                                            {isNotificationsPage && (
                                                <div className="ml-auto flex gap-1">
                                                    {notificationCounts.unread >
                                                        0 && (
                                                        <Badge
                                                            variant="default"
                                                            className="flex h-5 w-5 items-center justify-center rounded-full p-0 text-xs"
                                                        >
                                                            {notificationCounts.unread >
                                                            9
                                                                ? '9+'
                                                                : notificationCounts.unread}
                                                        </Badge>
                                                    )}
                                                    {notificationCounts.read >
                                                        0 && (
                                                        <Badge
                                                            variant="secondary"
                                                            className="flex h-5 w-5 items-center justify-center rounded-full p-0 text-xs"
                                                        >
                                                            {notificationCounts.read >
                                                            9
                                                                ? '9+'
                                                                : notificationCounts.read}
                                                        </Badge>
                                                    )}
                                                </div>
                                            )}
                                        </a>
                                    ) : (
                                        <Link
                                            href={item.href}
                                            prefetch
                                            className="flex w-full items-center justify-between"
                                        >
                                            <span className="flex items-center gap-2">
                                                {item.icon && <item.icon />}
                                                <span>{item.title}</span>
                                            </span>
                                            {isNotificationsPage && (
                                                <div className="ml-auto flex gap-1">
                                                    {notificationCounts.unread >
                                                        0 && (
                                                        <Badge
                                                            variant="default"
                                                            className="flex h-5 w-5 items-center justify-center rounded-full p-0 text-xs"
                                                        >
                                                            {notificationCounts.unread >
                                                            9
                                                                ? '9+'
                                                                : notificationCounts.unread}
                                                        </Badge>
                                                    )}
                                                    {notificationCounts.read >
                                                        0 && (
                                                        <Badge
                                                            variant="secondary"
                                                            className="flex h-5 w-5 items-center justify-center rounded-full p-0 text-xs"
                                                        >
                                                            {notificationCounts.read >
                                                            9
                                                                ? '9+'
                                                                : notificationCounts.read}
                                                        </Badge>
                                                    )}
                                                </div>
                                            )}
                                        </Link>
                                    )}
                                </SidebarMenuButton>
                            )}
                        </SidebarMenuItem>
                    );
                })}
            </SidebarMenu>
        </SidebarGroup>
    );
}
