import { useEffect, useState, type FormEvent } from "react";
import { Link, useNavigate } from "react-router-dom";
import { useAuth } from "../context/AuthContext";
import { getErrorMessage } from "../lib/getErrorMessage";
import { getAdminServices } from "../services/adminService";
import { getQueueHistory, type HistoryFilters, type QueueHistory } from "../services/adminQueueHistoryService";
import type { Service } from "../services/queueService";
import "./AdminServicesPage.css";
import "./AdminQueueHistoryPage.css";

const emptyFilters: HistoryFilters = { date: "", service_id: "", status: "" };

function formatTime(value: string | null) {
  return value ? new Date(value).toLocaleString() : "—";
}

export default function AdminQueueHistoryPage() {
  const { user, logoutUser } = useAuth();
  const navigate = useNavigate();
  const [services, setServices] = useState<Service[]>([]);
  const [serviceError, setServiceError] = useState("");
  const [draft, setDraft] = useState<HistoryFilters>(emptyFilters);
  const [filters, setFilters] = useState<HistoryFilters>(emptyFilters);
  const [page, setPage] = useState(1);
  const [refresh, setRefresh] = useState(0);
  const [report, setReport] = useState<QueueHistory | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [loggingOut, setLoggingOut] = useState(false);

  useEffect(() => {
    let ignore = false;
    setServiceError("");
    getAdminServices().then((result) => { if (!ignore) setServices(result); })
      .catch((requestError) => { if (!ignore) setServiceError(getErrorMessage(requestError)); });
    return () => { ignore = true; };
  }, [refresh]);

  useEffect(() => {
    const controller = new AbortController();
    setLoading(true);
    setError("");
    setReport(null);
    getQueueHistory(filters, page, controller.signal).then((result) => {
      if (controller.signal.aborted) return;
      setReport(result);
      if (!filters.date) setDraft((current) => ({ ...current, date: current.date || result.date }));
    }).catch((requestError) => {
      if (!controller.signal.aborted) setError(getErrorMessage(requestError));
    }).finally(() => {
      if (!controller.signal.aborted) setLoading(false);
    });
    return () => controller.abort();
  }, [filters, page, refresh]);

  function applyFilters(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setReport(null);
    setFilters({ ...draft });
    setPage(1);
  }

  function changePage(nextPage: number) {
    setReport(null);
    // Keep pagination on the date returned by the server, even across midnight.
    setFilters((current) => ({ ...current, date: current.date || report?.date || "" }));
    setPage(nextPage);
  }

  async function logout() {
    setLoggingOut(true);
    try { await logoutUser(); navigate("/login"); }
    catch (requestError) { setError(getErrorMessage(requestError)); }
    finally { setLoggingOut(false); }
  }

  if (!user) return null;

  return (
    <main className="staff-page admin-page">
      <header className="dashboard-header">
        <div><p className="eyebrow">QUEUEWISE</p><strong>Administrator Portal</strong></div>
        <div className="staff-header-actions">
          <span className="admin-identity">{user.name}</span>
          <Link className="secondary-button" to="/admin">Manage services</Link>
          <Link className="secondary-button" to="/admin/staff">Staff accounts</Link>
          <Link className="secondary-button" to="/staff">Queue operations</Link>
          <button className="secondary-button" type="button" disabled={loggingOut} onClick={() => void logout()}>{loggingOut ? "Logging out..." : "Log out"}</button>
        </div>
      </header>
      <section className="staff-content">
        <div className="welcome-block">
          <p className="eyebrow">QUEUE HISTORY & REPORTS</p>
          <h1>Your daily queue report.</h1>
          <p className="supporting-text">Review completed, cancelled, and skipped visits. Each queue entry counts as one visit.</p>
        </div>
        <form className="history-filters" onSubmit={applyFilters}>
          <label className="form-field"><span>Queue date</span><input type="date" required value={draft.date} onChange={(event) => setDraft({ ...draft, date: event.target.value })} /></label>
          <label className="form-field"><span>Service</span><select value={draft.service_id} onChange={(event) => setDraft({ ...draft, service_id: event.target.value })}><option value="">All services</option>{services.map((service) => <option key={service.id} value={service.id}>{service.name}{service.is_active ? "" : " (inactive)"}</option>)}</select></label>
          <label className="form-field"><span>Outcome</span><select value={draft.status} onChange={(event) => setDraft({ ...draft, status: event.target.value })}><option value="">All outcomes</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option><option value="skipped">Skipped</option></select></label>
          <button className="primary-button" type="submit" disabled={loggingOut}>Apply filters</button>
          <button className="secondary-button" type="button" disabled={loading || loggingOut} onClick={() => { setReport(null); setRefresh((value) => value + 1); }}>Refresh</button>
        </form>
        {serviceError && <div className="form-error staff-message" role="alert">Could not load service choices: {serviceError} Select Refresh to retry.</div>}
        {error && <div className="form-error staff-message" role="alert">{error}</div>}
        {loading && <p role="status">Loading queue history...</p>}
        {report && <>
          <h2>Daily totals · {report.date}</h2>
          <p className="supporting-text">{filters.service_id ? services.find((service) => String(service.id) === filters.service_id)?.name || "Selected service" : "All services"}. Totals include all outcomes and active entries, regardless of the outcome filter.</p>
          <div className="history-totals">
            {([ ["Total visits", report.summary.total], ["Completed", report.summary.completed], ["Cancelled", report.summary.cancelled], ["Skipped", report.summary.skipped], ["Still active", report.summary.active] ] as const).map(([label, count]) => <article key={label}><span>{label}</span><strong>{count}</strong></article>)}
          </div>
          <h2>Finished visits</h2>
          <p className="supporting-text">{report.entries.total} matching entries, newest joined first. Times are shown in your device’s timezone.</p>
          {report.entries.data.length === 0 ? <div className="staff-empty-list">No finished visits match these filters.</div> : <div className="history-table-wrap"><table className="history-table">
            <caption className="history-caption">Queue history for {report.date}</caption>
            <thead><tr>{["Queue #", "Customer", "Service", "Outcome", "Joined", "Called", "Completed"].map((label) => <th key={label} scope="col">{label}</th>)}</tr></thead>
            <tbody>{report.entries.data.map((entry) => <tr key={entry.id}>
              <td>#{entry.queue_number}</td><td>{entry.customer_name || "Unavailable"}</td><td>{entry.service?.name || "Unavailable"}</td>
              <td><span className={`admin-badge ${entry.status === "completed" ? "is-active" : "is-inactive"}`}>{entry.status}</span></td>
              <td>{formatTime(entry.joined_at)}</td><td>{formatTime(entry.called_at)}</td><td>{formatTime(entry.completed_at)}</td>
            </tr>)}</tbody>
          </table></div>}
          <div className="history-pagination">
            <button className="secondary-button" type="button" disabled={page <= 1 || loggingOut} onClick={() => changePage(page - 1)}>Previous</button>
            <span>Page {report.entries.current_page} of {report.entries.last_page}</span>
            <button className="secondary-button" type="button" disabled={page >= report.entries.last_page || loggingOut} onClick={() => changePage(page + 1)}>Next</button>
          </div>
        </>}
      </section>
    </main>
  );
}
