export function BeforeAfter() {
  return (
    <div
      style={{
        fontFamily: "'Inter', -apple-system, sans-serif",
        background: "#FAF8F6",
        minHeight: "100vh",
        display: "flex",
        alignItems: "center",
        justifyContent: "center",
        gap: 60,
        padding: "40px 32px",
      }}
    >
      {/* ── BEFORE ── */}
      <div style={{ display: "flex", flexDirection: "column", alignItems: "center", gap: 24 }}>
        <p style={{ fontSize: 11, fontWeight: 600, letterSpacing: "0.8px", textTransform: "uppercase", color: "#aaa", margin: 0 }}>
          Before — no creator selected
        </p>
        <Strip state="before" />
      </div>

      {/* divider */}
      <div style={{ width: 1, height: 220, background: "#e5e5e5", flexShrink: 0 }} />

      {/* ── AFTER ── */}
      <div style={{ display: "flex", flexDirection: "column", alignItems: "center", gap: 24 }}>
        <p style={{ fontSize: 11, fontWeight: 600, letterSpacing: "0.8px", textTransform: "uppercase", color: "#aaa", margin: 0 }}>
          After — creator selected
        </p>
        <Strip state="after" />
      </div>
    </div>
  );
}

/* ── Shared styles ── */
const PINK = "#E8305A";
const PINK_DARK = "#B01F3F";

function Strip({ state }: { state: "before" | "after" }) {
  const isAfter = state === "after";

  return (
    <div style={{ width: 380, display: "flex", flexDirection: "column", gap: 0 }}>

      {/* ── Down-arrow hint (hidden after selection) ── */}
      {!isAfter && (
        <div style={{ display: "flex", alignItems: "center", gap: 6, marginBottom: 8, paddingLeft: 4 }}>
          <svg width="16" height="16" viewBox="0 0 22 22" fill="none">
            <path d="M11 3V18" stroke={PINK} strokeWidth="1.8" strokeLinecap="round" />
            <path d="M7 14L11 18L15 14" stroke={PINK} strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
          </svg>
          <span style={{ fontSize: 11, color: PINK, fontWeight: 500 }}>Click to Select Creator</span>
        </div>
      )}

      {/* ── Strip row ── */}
      <div
        style={{
          display: "flex",
          alignItems: "center",
          background: "#fff",
          border: "1px solid #E5E5E5",
          borderRadius: 40,
          boxShadow: "0 2px 12px rgba(0,0,0,0.06)",
          padding: "6px 6px 6px 6px",
          gap: 10,
        }}
      >
        {/* Avatar */}
        <div
          style={{
            width: 44,
            height: 44,
            borderRadius: "50%",
            background: isAfter ? PINK : "#eee",
            flexShrink: 0,
            display: "flex",
            alignItems: "center",
            justifyContent: "center",
            fontSize: 16,
            fontWeight: 700,
            color: isAfter ? "#fff" : "#bbb",
            overflow: "hidden",
            cursor: "pointer",
          }}
        >
          {isAfter ? "S" : (
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#bbb" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
              <circle cx="12" cy="8" r="4" />
              <path d="M4 20c0-4 3.6-7 8-7s8 3 8 7" />
            </svg>
          )}
        </div>

        {/* Input */}
        <input
          readOnly
          value={isAfter ? "A video on building habits that actually stick" : ""}
          placeholder={isAfter ? "" : "Type your topic idea…"}
          style={{
            flex: 1,
            border: "none",
            outline: "none",
            background: "transparent",
            fontSize: 13,
            color: isAfter ? "#111" : "#aaa",
            fontFamily: "inherit",
          }}
        />

        {/* Counter */}
        {isAfter && (
          <span style={{ fontSize: 11, color: "#bbb", flexShrink: 0 }}>52/100</span>
        )}

        {/* Send button */}
        <button
          style={{
            width: 36,
            height: 36,
            borderRadius: "50%",
            border: "none",
            background: isAfter ? PINK : "#eee",
            color: isAfter ? "#fff" : "#ccc",
            cursor: isAfter ? "pointer" : "not-allowed",
            display: "flex",
            alignItems: "center",
            justifyContent: "center",
            flexShrink: 0,
          }}
        >
          <svg width="14" height="14" viewBox="0 0 16 16" fill="none">
            <path d="M14 8L2 2l2 6-2 6 12-6z" fill="currentColor" />
          </svg>
        </button>
      </div>

      {/* ── Creator card (only after) ── */}
      {isAfter && (
        <div
          style={{
            marginTop: 12,
            background: "#fff",
            border: "1px solid #E5E5E5",
            borderRadius: 12,
            padding: "12px 16px",
            display: "flex",
            alignItems: "center",
            gap: 12,
            boxShadow: "0 2px 8px rgba(0,0,0,0.04)",
          }}
        >
          {/* Avatar */}
          <div
            style={{
              width: 40,
              height: 40,
              borderRadius: "50%",
              background: PINK,
              flexShrink: 0,
              display: "flex",
              alignItems: "center",
              justifyContent: "center",
              fontSize: 15,
              fontWeight: 700,
              color: "#fff",
            }}
          >
            S
          </div>
          {/* Info */}
          <div style={{ flex: 1, minWidth: 0 }}>
            <div style={{ fontWeight: 600, fontSize: 13, color: "#111" }}>Sarah Chen</div>
            <div style={{ fontSize: 11, color: "#888", marginTop: 2 }}>Habit Formation · Mindset · Productivity</div>
          </div>
          {/* Price badge */}
          <div
            style={{
              background: "#FFF0F3",
              color: PINK,
              fontSize: 11,
              fontWeight: 600,
              padding: "4px 10px",
              borderRadius: 20,
              flexShrink: 0,
            }}
          >
            from $50
          </div>
        </div>
      )}

      {/* ── Step hints ── */}
      <div style={{ marginTop: 16, display: "flex", flexDirection: "column", alignItems: "flex-start", gap: 10, paddingLeft: 4 }}>
        {!isAfter ? (
          <>
            <Step n={1} text="Click the avatar to pick a creator" />
            <Step n={2} text="Type your topic idea" />
            <Step n={3} text="Add details & fund the video" />
          </>
        ) : (
          <>
            <Step n={2} text="Type your topic idea" done />
            <Step n={3} text="Add details & fund the video" />
          </>
        )}
      </div>
    </div>
  );
}

function Step({ n, text, done }: { n: number; text: string; done?: boolean }) {
  return (
    <div style={{ display: "flex", alignItems: "center", gap: 10, width: 220 }}>
      <div
        style={{
          width: 18,
          height: 18,
          borderRadius: "50%",
          background: done ? "#ccc" : PINK,
          color: "#fff",
          fontSize: 10,
          fontWeight: 600,
          display: "flex",
          alignItems: "center",
          justifyContent: "center",
          flexShrink: 0,
        }}
      >
        {n}
      </div>
      <span style={{ fontSize: 12, color: done ? "#bbb" : "#888", textDecoration: done ? "line-through" : "none" }}>
        {text}
      </span>
    </div>
  );
}
