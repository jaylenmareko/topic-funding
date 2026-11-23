export function BeforeAfter() {
  return (
    <div style={{ background: '#FAF8F6', minHeight: '100vh', padding: '40px', fontFamily: 'Arial, sans-serif', color: '#111' }}>
      <div style={{ display: 'flex', gap: '40px', alignItems: 'flex-start', justifyContent: 'center' }}>
        <div style={{ width: '420px' }}>
          <div style={{ fontSize: '11px', fontWeight: 700, color: '#999', letterSpacing: '0.08em', textTransform: 'uppercase', marginBottom: '14px' }}>Before</div>
          <div style={{ background: '#fff', border: '1px solid #E5E5E5', borderRadius: '40px', boxShadow: '0 2px 12px rgba(0,0,0,0.06)', padding: '6px', display: 'flex', alignItems: 'center', gap: '10px' }}>
            <div style={{ width: '44px', height: '44px', borderRadius: '50%', background: '#E8305A', display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#fff', flexShrink: 0 }}>
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
            </div>
            <div style={{ flex: 1, color: '#aaa', fontSize: '13px' }}>Type your topic idea…</div>
            <div style={{ width: '40px', height: '40px', borderRadius: '50%', background: '#eee', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
              <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="M14 8L2 2l2 6-2 6 12-6z" fill="#ccc"/></svg>
            </div>
          </div>
          <div style={{ marginTop: '16px', display: 'flex', flexDirection: 'column', gap: '10px', paddingLeft: '4px' }}>
            <Step n="1" text="Click the avatar to pick a creator" />
            <Step n="2" text="Type your topic idea" />
            <Step n="3" text="Add details & fund the video" />
          </div>
        </div>

        <div style={{ width: '1px', height: '220px', background: '#E5E5E5' }} />

        <div style={{ width: '420px' }}>
          <div style={{ fontSize: '11px', fontWeight: 700, color: '#999', letterSpacing: '0.08em', textTransform: 'uppercase', marginBottom: '14px' }}>After</div>
          <div style={{ background: '#fff', border: '1px solid #E5E5E5', borderRadius: '40px', boxShadow: '0 2px 12px rgba(0,0,0,0.06)', padding: '6px', display: 'flex', alignItems: 'center', gap: '10px' }}>
            <div style={{ width: '44px', height: '44px', borderRadius: '50%', background: '#E8305A', display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#fff', fontWeight: 700, flexShrink: 0 }}>S</div>
            <div style={{ flex: 1, color: '#111', fontSize: '13px' }}>A video on building habits that actually stick</div>
            <div style={{ color: '#bbb', fontSize: '11px', flexShrink: 0 }}>52/100</div>
            <div style={{ width: '40px', height: '40px', borderRadius: '50%', background: '#E8305A', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
              <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="M14 8L2 2l2 6-2 6 12-6z" fill="#fff"/></svg>
            </div>
          </div>
          <div style={{ marginTop: '12px', background: '#fff', border: '1px solid #E5E5E5', borderRadius: '12px', padding: '12px 16px', display: 'flex', alignItems: 'center', gap: '12px', boxShadow: '0 2px 8px rgba(0,0,0,0.04)' }}>
            <div style={{ width: '40px', height: '40px', borderRadius: '50%', background: '#E8305A', color: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: 700 }}>S</div>
            <div style={{ flex: 1, minWidth: 0 }}>
              <div style={{ fontWeight: 600, fontSize: '13px' }}>Sarah Chen</div>
              <div style={{ fontSize: '11px', color: '#888', marginTop: '2px' }}>Habit Formation · Mindset · Productivity</div>
            </div>
            <div style={{ background: '#FFF0F3', color: '#E8305A', fontSize: '11px', fontWeight: 600, padding: '4px 10px', borderRadius: '20px' }}>from $50</div>
            <button style={{ background: 'none', border: 'none', color: '#ccc', fontSize: '18px', lineHeight: 1, cursor: 'pointer' }}>&times;</button>
          </div>
          <div style={{ marginTop: '16px', display: 'flex', flexDirection: 'column', gap: '10px', paddingLeft: '4px' }}>
            <Step n="2" text="Type your topic idea" done />
            <Step n="3" text="Add details & fund the video" />
          </div>
        </div>
      </div>
    </div>
  );
}

function Step({ n, text, done = false }: { n: string; text: string; done?: boolean }) {
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: '10px', width: '260px' }}>
      <div style={{ width: '18px', height: '18px', borderRadius: '50%', background: done ? '#CCC' : '#E8305A', color: '#fff', fontSize: '10px', fontWeight: 600, display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>{n}</div>
      <div style={{ fontSize: '12px', color: done ? '#bbb' : '#888', textDecoration: done ? 'line-through' : 'none' }}>{text}</div>
    </div>
  );
}
