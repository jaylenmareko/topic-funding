export function BeforeAfter() {
  return (
    <div style={{ background: '#FAF8F6', minHeight: '100vh', padding: '40px 32px', fontFamily: 'Arial, sans-serif' }}>
      <div style={{ display: 'flex', gap: 48, justifyContent: 'center', alignItems: 'flex-start' }}>
        <Panel title="Before" selected={false} />
        <Panel title="After" selected={true} />
      </div>
    </div>
  );
}

function Panel({ title, selected }: { title: string; selected: boolean }) {
  return (
    <div style={{ width: 420 }}>
      <div style={{ fontSize: 11, fontWeight: 700, color: '#999', textTransform: 'uppercase', letterSpacing: '0.08em', marginBottom: 16 }}>{title}</div>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
        <Strip selected={selected} />
        {selected && <CreatorCard />}
        <Steps selected={selected} />
      </div>
    </div>
  );
}

function Strip({ selected }: { selected: boolean }) {
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 10, background: '#fff', border: '1px solid #E5E5E5', borderRadius: 40, padding: 6, boxShadow: '0 2px 12px rgba(0,0,0,0.06)' }}>
      {!selected ? (
        <div style={{ width: 44, height: 44, borderRadius: '50%', background: '#E8305A', display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#fff' }}>
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
        </div>
      ) : (
        <div style={{ width: 44, height: 44, borderRadius: '50%', background: '#E8305A', color: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: 700 }}>S</div>
      )}
      <div style={{ flex: 1, color: selected ? '#111' : '#aaa', fontSize: 13 }}>{selected ? 'A video on building habits that actually stick' : 'Type your topic idea…'}</div>
      {selected && <div style={{ color: '#bbb', fontSize: 11 }}>52/100</div>}
      <div style={{ width: 40, height: 40, borderRadius: '50%', background: selected ? '#E8305A' : '#eee', display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#fff' }}>
        <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="M14 8L2 2l2 6-2 6 12-6z" fill="currentColor"/></svg>
      </div>
    </div>
  );
}

function CreatorCard() {
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 12, background: '#fff', border: '1px solid #E5E5E5', borderRadius: 12, padding: '12px 16px', boxShadow: '0 2px 8px rgba(0,0,0,0.04)' }}>
      <div style={{ width: 40, height: 40, borderRadius: '50%', background: '#E8305A', color: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: 700 }}>S</div>
      <div style={{ flex: 1, minWidth: 0 }}>
        <div style={{ fontWeight: 600, fontSize: 13 }}>Sarah Chen</div>
        <div style={{ fontSize: 11, color: '#888', marginTop: 2 }}>Habit Formation · Mindset · Productivity</div>
      </div>
      <div style={{ background: '#FFF0F3', color: '#E8305A', fontSize: 11, fontWeight: 600, padding: '4px 10px', borderRadius: 20 }}>from $50</div>
      <button style={{ background: 'none', border: 'none', color: '#ccc', fontSize: 18, cursor: 'pointer', lineHeight: 1 }}>&times;</button>
    </div>
  );
}

function Steps({ selected }: { selected: boolean }) {
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 10, paddingLeft: 4 }}>
      {selected ? (
        <>
          <Step n={2} text="Type your topic idea" done />
          <Step n={3} text="Add details & fund the video" />
        </>
      ) : (
        <>
          <Step n={1} text="Click the avatar to pick a creator" />
          <Step n={2} text="Type your topic idea" />
          <Step n={3} text="Add details & fund the video" />
        </>
      )}
    </div>
  );
}

function Step({ n, text, done }: { n: number; text: string; done?: boolean }) {
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 10, width: 260 }}>
      <div style={{ width: 18, height: 18, borderRadius: '50%', background: done ? '#CCC' : '#E8305A', color: '#fff', fontSize: 10, fontWeight: 600, display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>{n}</div>
      <div style={{ fontSize: 12, color: done ? '#bbb' : '#888' }}>{text}</div>
    </div>
  );
}
