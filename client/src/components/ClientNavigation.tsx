import './ClientNavigation.css'

const tabs = [
  { href: '/app', title: 'Сьогодні' },
  { href: '/app/meal-plan', title: 'Раціон' },
  { href: '/app/resources', title: 'Корисне' },
  { href: '/app/progress', title: 'Прогрес' },
] as const

function selected(path: string, href: string): boolean {
  if (href === '/app') return path === '/app' || path === '/app/' || path === '/app/index.html'
  return path === href || path.startsWith(href + '/')
}

export function ClientNavigation({ path }: { path: string }) {
  return <nav className="client-navigation" aria-label="Основна навігація">
    {tabs.map(tab => <a key={tab.href} href={tab.href} aria-current={selected(path, tab.href) ? 'page' : undefined}
      className={selected(path, tab.href) ? 'active' : undefined}>{tab.title}</a>)}
  </nav>
}
