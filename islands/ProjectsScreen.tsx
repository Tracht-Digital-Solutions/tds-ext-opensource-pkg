import { useEffect, useState } from "react";

import { describeFailureFrom, sendJson } from "@tracht-digital-solutions/tds-shared/api";
import { Alert, Spinner, toast } from "@tracht-digital-solutions/tds-shared/components";
import { invalidate, staleClass, useCachedJson } from "@tracht-digital-solutions/tds-shared/data";
import { formatCents, formatDateTime, parseEuros } from "@tracht-digital-solutions/tds-shared/format";

/**
 * Open Source — pick a project, edit what changes with a release, see donations.
 *
 * - **`apiFetch`/`sendJson`, never a relative `fetch`.** A relative path hits
 *   the panel's own host and turns every failure into a calm empty list.
 * - **Spread, never replace.** Every field edit is `{ ...draft, x }`.
 * - **A cache report is factual.** `not_configured` is the normal state before
 *   the site is paired; it is said as such, not as success and not as failure.
 * - **No `ToastHost` here.** The panel host owns the only one.
 */

export interface Project {
  id: number;
  slug: string;
  name: string;
  site_url: string | null;
  repo_url: string;
  mirror_url: string | null;
  issues_url: string | null;
  license: string;
  version: string | null;
  version_date: string | null;
  download_url: string | null;
  tagline_de: string;
  tagline_en: string;
  status_de: string | null;
  status_en: string | null;
  donations_enabled: boolean;
  donation_presets: string;
  donation_min: number;
  donation_max: number;
  currency: string;
  published: boolean;
}

interface ProjectsPayload {
  projects: Project[];
  paymentsReady: boolean;
  webhookReady: boolean;
}

interface Donation {
  id: number;
  project: string | null;
  amount: number;
  currency: string;
  lang: string;
  status: string;
  created_at: string;
  paid_at: string | null;
}

interface DonationsPayload {
  donations: Donation[];
  summary: { currency: string; count: number; total: number; last30: number }[];
}

/** What a save did to the public site, in words that are true. */
export function cacheMessage(status: string | undefined): { tone: "success" | "warning" | "info"; text: string } {
  switch (status) {
    case "refreshed":
      return { tone: "success", text: "Gespeichert. Die Webseite zeigt den neuen Stand." };
    case "not_configured":
      return {
        tone: "info",
        text: "Gespeichert. Die Webseite ist noch nicht gekoppelt und zeigt den neuen Stand erst nach ihrem nächsten Neuaufbau.",
      };
    default:
      return {
        tone: "warning",
        text: "Gespeichert, aber die Webseite hat den Neuaufbau nicht bestätigt. Er lässt sich unten erneut anstoßen.",
      };
  }
}

/** `"500,1000"` → `"5,00; 10,00"`, for a field people type euros into. */
export function presetsToEuros(csv: string): string {
  return csv
    .split(",")
    .map((c) => Number(c.trim()))
    .filter((n) => Number.isFinite(n) && n > 0)
    .map((n) => (n / 100).toFixed(2).replace(".", ","))
    .join("; ");
}

/** `"5; 10,50"` → `"500,1050"`; null when any entry is not an amount. */
export function eurosToPresets(text: string): string | null {
  const parts = text.split(/[;\n]/).map((p) => p.trim()).filter((p) => p !== "");
  const cents: number[] = [];
  for (const part of parts) {
    const value = parseEuros(part);
    if (value === null) return null;
    cents.push(Math.round(value));
  }
  return cents.join(",");
}

const STATUS_LABEL: Record<string, string> = {
  paid: "bezahlt",
  open: "offen",
  expired: "abgelaufen",
  failed: "fehlgeschlagen",
};

const STATUS_CHIP: Record<string, string> = {
  paid: "chip chip--success",
  open: "chip chip--neutral",
  expired: "chip chip--neutral",
  failed: "chip chip--danger",
};

export default function ProjectsScreen() {
  const query = useCachedJson<ProjectsPayload>("/opensource/admin/projects");
  const donations = useCachedJson<DonationsPayload>("/opensource/admin/donations?status=all&limit=50");
  const [slug, setSlug] = useState<string | null>(null);
  const [draft, setDraft] = useState<Project | null>(null);
  const [presets, setPresets] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<{ tone: "success" | "warning" | "info"; text: string } | null>(null);

  const projects = query.data?.projects ?? [];
  const selected = projects.find((p) => p.slug === slug) ?? projects[0] ?? null;

  // A new selection (or the first load) starts a fresh draft. A refresh of the
  // SAME project never overwrites what somebody is typing.
  useEffect(() => {
    if (selected && draft?.slug !== selected.slug) {
      setDraft({ ...selected });
      setPresets(presetsToEuros(selected.donation_presets));
      setNotice(null);
      setError(null);
    }
  }, [selected, draft?.slug]);

  const set = <K extends keyof Project>(key: K, value: Project[K]) => {
    setDraft((d) => (d ? { ...d, [key]: value } : d));
  };

  const save = async (): Promise<void> => {
    if (!draft) return;
    const cents = eurosToPresets(presets);
    if (cents === null) {
      setError("Die Spendenbeträge bitte als Euro-Beträge mit Semikolon trennen, z. B. 5; 10; 25.");
      return;
    }
    setBusy(true);
    setError(null);
    setNotice(null);
    try {
      const res = await sendJson(`/opensource/admin/projects/${draft.slug}`, "PUT", { ...draft, donation_presets: cents });
      if (!res.ok) {
        setError(await describeFailureFrom(res, "Speichern fehlgeschlagen"));
        return;
      }
      const body = (await res.json()) as { project: Project; cache?: { cache_status?: string } };
      setDraft({ ...body.project });
      setPresets(presetsToEuros(body.project.donation_presets));
      setNotice(cacheMessage(body.cache?.cache_status));
      invalidate("/opensource/");
    } catch (err) {
      setError(await describeFailureFrom(null, "Speichern fehlgeschlagen"));
      console.error(err);
    } finally {
      setBusy(false);
    }
  };

  const rebuild = async (): Promise<void> => {
    if (!draft) return;
    setBusy(true);
    try {
      const res = await sendJson(`/opensource/admin/projects/${draft.slug}/cache/rebuild`, "POST", {});
      const body = (await res.json().catch(() => ({}))) as { cache?: { cache_status?: string } };
      setNotice(cacheMessage(body.cache?.cache_status ?? (res.ok ? "refreshed" : "failed")));
    } catch {
      toast.danger("Die Webseite war nicht erreichbar.");
    } finally {
      setBusy(false);
    }
  };

  if (query.loading) {
    return (
      <p aria-busy="true">
        <Spinner /> Projekte werden geladen …
      </p>
    );
  }

  if (!query.data) {
    return <Alert tone="danger">Die Projekte konnten nicht geladen werden.</Alert>;
  }

  return (
    <div className={staleClass(query.stale, "tds-stack tds-stack--loose")} aria-busy={query.stale}>
      {query.error && query.data ? <Alert tone="warning">Ältere Daten: die Aktualisierung ist fehlgeschlagen.</Alert> : null}
      {!query.data.paymentsReady ? (
        <Alert tone="info">
          Stripe ist noch nicht eingerichtet (Einstellungen → Zahlungen). Bis dahin zeigen die Webseiten keinen Spendenknopf.
        </Alert>
      ) : null}
      {query.data.paymentsReady && !query.data.webhookReady ? (
        <Alert tone="warning">
          Das Webhook-Geheimnis für Spenden fehlt. Spenden gehen ein, erscheinen hier aber nicht als bezahlt.
        </Alert>
      ) : null}

      {projects.length === 0 ? (
        <p className="tds-empty">Noch kein Projekt angelegt.</p>
      ) : (
        <div className="tds-toolbar">
          <label className="tds-field-row">
            <span>Projekt</span>
            <select className="field-boxed" value={selected?.slug ?? ""} onChange={(e) => setSlug(e.target.value)}>
              {projects.map((p) => (
                <option key={p.slug} value={p.slug}>
                  {p.name}
                </option>
              ))}
            </select>
          </label>
        </div>
      )}

      {draft ? (
        <form
          className="tds-card p-4 tds-stack"
          onSubmit={(e) => {
            e.preventDefault();
            void save();
          }}
        >
          <h2 className="text-lg font-semibold">{draft.name}</h2>
          {draft.site_url ? (
            <p className="marginalia">
              Webseite: <a className="link-underline" href={draft.site_url}>{draft.site_url}</a>
            </p>
          ) : null}

          <label className="tds-field-row">
            <span>Version</span>
            <input className="field-boxed" value={draft.version ?? ""} onChange={(e) => set("version", e.target.value)} placeholder="0.4.0-alpha" />
          </label>
          <label className="tds-field-row">
            <span>Veröffentlicht am</span>
            <input className="field-boxed" type="date" value={draft.version_date ?? ""} onChange={(e) => set("version_date", e.target.value)} />
          </label>
          <label className="tds-field-row">
            <span>Download</span>
            <input className="field-boxed" type="url" value={draft.download_url ?? ""} onChange={(e) => set("download_url", e.target.value)} />
          </label>
          <label className="tds-field-row">
            <span>Stand (Deutsch)</span>
            <textarea className="field-boxed" rows={2} maxLength={400} value={draft.status_de ?? ""} onChange={(e) => set("status_de", e.target.value)} />
          </label>
          <label className="tds-field-row">
            <span>Stand (Englisch)</span>
            <textarea className="field-boxed" rows={2} maxLength={400} value={draft.status_en ?? ""} onChange={(e) => set("status_en", e.target.value)} />
          </label>
          <label className="tds-field-row">
            <span>Quellcode</span>
            <input className="field-boxed" type="url" required value={draft.repo_url} onChange={(e) => set("repo_url", e.target.value)} />
          </label>
          <label className="tds-field-row">
            <span>Spiegel</span>
            <input className="field-boxed" type="url" value={draft.mirror_url ?? ""} onChange={(e) => set("mirror_url", e.target.value)} />
          </label>
          <label className="tds-field-row">
            <span>Lizenz (SPDX)</span>
            <input className="field-boxed" value={draft.license} onChange={(e) => set("license", e.target.value)} />
          </label>

          <h3 className="font-semibold">Spenden</h3>
          <label className="tds-toggle-row">
            <input type="checkbox" checked={draft.donations_enabled} onChange={(e) => set("donations_enabled", e.target.checked)} />
            <span>Spenden annehmen</span>
          </label>
          <label className="tds-field-row">
            <span>Vorschläge in Euro</span>
            <input className="field-boxed" value={presets} onChange={(e) => setPresets(e.target.value)} placeholder="5; 10; 25" />
          </label>
          <p className="marginalia">
            Freie Beträge zwischen {formatCents(draft.donation_min, { currency: draft.currency })} und{" "}
            {formatCents(draft.donation_max, { currency: draft.currency })}.
          </p>
          <label className="tds-toggle-row">
            <input type="checkbox" checked={draft.published} onChange={(e) => set("published", e.target.checked)} />
            <span>Veröffentlicht (die Webseite liest das Projekt)</span>
          </label>

          <Alert tone="danger">{error}</Alert>
          {notice ? <Alert tone={notice.tone}>{notice.text}</Alert> : null}

          <div className="tds-toolbar">
            <button type="submit" className="btn btn-primary" disabled={busy}>
              {busy ? "Wird gespeichert …" : "Speichern"}
            </button>
            <button type="button" className="btn btn-ghost" disabled={busy} onClick={() => void rebuild()}>
              Webseite neu aufbauen
            </button>
          </div>
        </form>
      ) : null}

      <section className="tds-card p-4 tds-stack">
        <h2 className="text-lg font-semibold">Spenden</h2>
        {donations.data?.summary.length ? (
          <ul className="tds-list">
            {donations.data.summary.map((s) => (
              <li key={s.currency} className="tds-list__row">
                <span className="tabular-nums">{formatCents(s.total, { currency: s.currency })}</span>
                <span className="marginalia">
                  {s.count} Spenden · {formatCents(s.last30, { currency: s.currency })} in den letzten 30 Tagen
                </span>
              </li>
            ))}
          </ul>
        ) : null}
        {donations.loading ? (
          <p aria-busy="true">
            <Spinner /> Spenden werden geladen …
          </p>
        ) : donations.data && donations.data.donations.length > 0 ? (
          <table className="tds-table">
            <thead>
              <tr>
                <th scope="col">Datum</th>
                <th scope="col">Projekt</th>
                <th scope="col">Betrag</th>
                <th scope="col">Status</th>
              </tr>
            </thead>
            <tbody>
              {donations.data.donations.map((d) => (
                <tr key={d.id}>
                  <td>{formatDateTime(d.paid_at ?? d.created_at)}</td>
                  <td>{d.project ?? "—"}</td>
                  <td className="tabular-nums">{formatCents(d.amount, { currency: d.currency })}</td>
                  <td>
                    <span className={STATUS_CHIP[d.status] ?? "chip chip--neutral"}>{STATUS_LABEL[d.status] ?? d.status}</span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : donations.data ? (
          <p className="tds-empty">Noch keine Spenden.</p>
        ) : (
          <Alert tone="warning">Die Spenden konnten nicht geladen werden.</Alert>
        )}
        <p className="marginalia">Ohne Namen und E-Mail: wer gespendet hat, steht nur in Stripe.</p>
      </section>
    </div>
  );
}
