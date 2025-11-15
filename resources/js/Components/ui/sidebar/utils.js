import { inject, provide } from 'vue';

export const SIDEBAR_COOKIE_NAME = 'sidebar:state';
export const SIDEBAR_COOKIE_MAX_AGE = 60 * 60 * 24 * 7;
export const SIDEBAR_WIDTH = '16rem';
export const SIDEBAR_WIDTH_MOBILE = '18rem';
export const SIDEBAR_WIDTH_ICON = '3rem';
export const SIDEBAR_KEYBOARD_SHORTCUT = 'b';

const SidebarContextSymbol = Symbol('Sidebar');

export function useSidebar() {
  const context = inject(SidebarContextSymbol);
  if (!context) {
    throw new Error('useSidebar must be used within a SidebarProvider');
  }
  return context;
}

export function provideSidebarContext(context) {
  provide(SidebarContextSymbol, context);
}
