import { useState } from "react";

import { apiFetch, describeFailureFrom, sendJson } from "@tracht-digital-solutions/tds-shared/api";
import { Alert, ConfirmDialog, Spinner, toast } from "@tracht-digital-solutions/tds-shared/components";
import { invalidate, staleClass, useCachedJson } from "@tracht-digital-solutions/tds-shared/data";

/**
 * Einstellungen → Open-Source-Seiten: one site connection per project.
 *
 * A project's site reads its data with the key this pairing hands out, and the
 * panel sends it cache events through the same connection. Until then the site
 * shows the values it was built with.
 */

interface ProjectRow {
  slug: string;
  name: string;
  site_url: string | null;
}

interface Connection {
  origin?: string;
  connectedAt?: string;
}

interface Pairing {
  code?: string;
  connectUrl?: string;
  expiresAt?: string;
  delivered?: boolean;
}

function ProjectConnection({ project }: { project: ProjectRow }) {
  const path = `/opensource/admin/projects/${project.slug}/connection`;
  const query = useCachedJson<{ connection: Connection }>(path);
  const [origin, setOrigin] = useState(project.site_url ?? "https://");
  const [pairing, setPairing] = useState<Pairing | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [confirm, setConfirm] = useState(false);

  // 404 is "not paired", the normal state of a new project.
  const connection = query.data?.connection ?? null;

  const pair = async (): Promise<void> => {
    setBusy(true);
    setError(null);
    setPairing(null);
    try {
      const res = await sendJson(`${path}/pairing`, "POST", { origin });
      if (!res.ok) {
        setError(await describeFailureFrom(res, "Kopplung fehlgeschlagen"));
        return;
      }
      setPairing((await res.json()) as Pairing);
      invalidate(path);
      toast.success("Kopplung angelegt.");
    } catch {
      setError(await describeFailureFrom(null, "Kopplung fehlgeschlagen"));
    } finally {
      setBusy(false);
    }
  };

  const disconnect = async (): Promise<void> => {
    setConfirm(false);
    setBusy(true);
    try {
      const res = await apiFetch(path, { method: "DELETE" });
      if (!res.ok) {
        setError(await describeFailureFrom(res, "Trennen fehlgeschlagen"));
        return;
      }
      invalidate(path);
      toast.success("Verbindung getrennt.");
    } catch {
      setError(await describeFailureFrom(null, "Trennen fehlgeschlagen"));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className={staleClass(query.stale, "tds-card p-4 tds-stack")} aria-busy={query.stale}>
      <h3 className="font-semibold">{project.name}</h3>
      <Alert tone="danger">{error}</Alert>
      {query.loading ? (
        <p aria-busy="true">
          <Spinner /> Verbindung wird geprüft …
        </p>
      ) : connection ? (
        <div className="tds-stack">
          <p>
            Verbunden mit <code>{connection.origin}</code>
            {connection.connectedAt ? ` seit ${connection.connectedAt}` : ""}.
          </p>
          <button type="button" className="btn btn-ghost" disabled={busy} onClick={() => setConfirm(true)}>
            Verbindung trennen
          </button>
        </div>
      ) : (
        <form
          className="tds-stack"
          onSubmit={(e) => {
            e.preventDefault();
            void pair();
          }}
        >
          <p>Noch nicht gekoppelt. Die Seite zeigt bis dahin die Werte ihres letzten Builds.</p>
          <label className="tds-field-row">
            <span>Origin der Webseite</span>
            <input className="field-boxed" type="url" required value={origin} onChange={(e) => setOrigin(e.target.value)} />
          </label>
          <button type="submit" className="btn btn-primary" disabled={busy}>
            {busy ? "Wird gekoppelt …" : "Kopplung anlegen"}
          </button>
        </form>
      )}

      {pairing && !pairing.delivered ? (
        <Alert tone="info">
          Diese Daten einmalig auf <code>{origin}/install</code> eintragen. Sie werden nicht wieder angezeigt.
          <br />
          Code: <code>{pairing.code}</code>
          {pairing.connectUrl ? (
            <>
              <br />
              Adresse: <code>{pairing.connectUrl}</code>
            </>
          ) : null}
        </Alert>
      ) : null}

      <ConfirmDialog
        open={confirm}
        title="Verbindung trennen?"
        message="Die Webseite liest danach nichts mehr und zeigt nur noch, was in ihrem Cache liegt."
        confirmLabel="Trennen"
        onConfirm={() => void disconnect()}
        onCancel={() => setConfirm(false)}
      />
    </div>
  );
}

export default function ConnectionSettings() {
  const query = useCachedJson<{ projects: ProjectRow[] }>("/opensource/admin/projects");
  if (query.loading) {
    return (
      <p aria-busy="true">
        <Spinner /> Projekte werden geladen …
      </p>
    );
  }
  const projects = query.data?.projects ?? [];
  if (!query.data) {
    return <Alert tone="danger">Die Projekte konnten nicht geladen werden.</Alert>;
  }
  if (projects.length === 0) {
    return <p className="tds-empty">Noch kein Projekt angelegt.</p>;
  }
  return (
    <div className="tds-stack">
      {projects.map((p) => (
        <ProjectConnection key={p.slug} project={p} />
      ))}
    </div>
  );
}
