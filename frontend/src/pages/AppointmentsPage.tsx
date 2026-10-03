import { useEffect, useRef, useState, type FormEvent } from "react";
import { Link } from "react-router-dom";
import { useAuth } from "../context/AuthContext";
import { getErrorMessage } from "../lib/getErrorMessage";
import { getServices, type Service } from "../services/queueService";
import { bookAppointment, cancelAppointment, checkInAppointment, getAppointments, getSlots, type Appointment } from "../services/appointmentService";
import { getUpcomingAppointments, getAppointmentHistory, markNoShow, rescheduleAppointment } from "../services/appointmentService";
import AppointmentRescheduleForm from "../components/AppointmentRescheduleForm";
import "./AdminServicesPage.css";
import "./AdminQueueHistoryPage.css";

function todayInManila() {
  return new Intl.DateTimeFormat("en-CA", { timeZone: "Asia/Manila", year: "numeric", month: "2-digit", day: "2-digit" }).format(new Date());
}
function appointmentTime(value: string) {
  return new Date(value).toLocaleString("en-PH", { timeZone: "Asia/Manila", dateStyle: "medium", timeStyle: "short" });
}

export default function AppointmentsPage() {
  const { user } = useAuth();
  const staff = user?.role !== "customer";
  const [date, setDate] = useState(todayInManila);
  const [serviceId, setServiceId] = useState("");
  const [services, setServices] = useState<Service[]>([]);
  const [slots, setSlots] = useState<string[]>([]);
  const [time, setTime] = useState("");
  const [overview, setOverview] = useState(true);
  const [history, setHistory] = useState(false);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [editing, setEditing] = useState<Appointment | null>(null);
  const [appointments, setAppointments] = useState<Appointment[]>([]);
  const [error, setError] = useState("");
  const [loadError, setLoadError] = useState("");
  const [slotError, setSlotError] = useState("");
  const [serviceError, setServiceError] = useState("");
  const [success, setSuccess] = useState("");
  const [loaded, setLoaded] = useState(false);
  const [busy, setBusy] = useState(false);
  const [refresh, setRefresh] = useState(0);
  const operation = useRef(false);
  const revision = useRef(0);

  useEffect(() => {
    if (staff) return;
    let cancelled = false;
    getServices().then((result) => { if (!cancelled) { setServices(result); setServiceError(""); } })
      .catch((failure) => { if (!cancelled) setServiceError(getErrorMessage(failure)); });
    return () => { cancelled = true; };
  }, [staff, refresh]);

  useEffect(() => {
    const controller = new AbortController();
    let fetching = false;
    setLoaded(false);
    setAppointments([]);
    async function load() {
      if (fetching || operation.current || (!history && !overview && !date)) return;
      fetching = true;
      const version = revision.current;
      try {
        const result = history ? await getAppointmentHistory(staff, page, controller.signal) : overview ? await getUpcomingAppointments(staff, page, controller.signal) : null;
        const data = result ? result.data : await getAppointments(date, staff, controller.signal);
        if (!controller.signal.aborted && version === revision.current) { setAppointments(data); setLastPage(result?.last_page ?? 1); setLoaded(true); setLoadError(""); }
      } catch (failure) {
        if (!controller.signal.aborted && version === revision.current) { setLoadError(getErrorMessage(failure)); setAppointments([]); setLoaded(false); }
      } finally { fetching = false; }
    }
    void load();
    const timer = window.setInterval(() => void load(), 5000);
    return () => { controller.abort(); window.clearInterval(timer); };
  }, [date, staff, refresh, history, page, overview]);

  useEffect(() => {
    const controller = new AbortController();
    setSlots([]); setTime(""); setSlotError("");
    if (!staff && serviceId && date) {
      getSlots(date, serviceId, controller.signal).then((result) => { if (!controller.signal.aborted) setSlots(result); })
        .catch((failure) => { if (!controller.signal.aborted) setSlotError(getErrorMessage(failure)); });
    }
    return () => controller.abort();
  }, [date, serviceId, staff, refresh]);

  async function act(action: () => Promise<void>, message: string) {
    if (operation.current) return;
    operation.current = true; revision.current += 1; setBusy(true); setError(""); setSuccess("");
    try { await action(); setSuccess(message); }
    catch (failure) { setError(getErrorMessage(failure)); }
    finally { operation.current = false; setBusy(false); setRefresh((value) => value + 1); }
  }
  function book(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    void act(() => bookAppointment(date, time, serviceId), "Appointment booked. Staff will check you in when you arrive.");
  }

  function saveReschedule(newDate: string, newTime: string) {
    if (!editing) return;
    void act(async () => {
      await rescheduleAppointment(editing.id, newDate, newTime);
      setEditing(null); setDate(newDate); setOverview(true); setHistory(false); setPage(1);
    }, "Appointment rescheduled. Your previous slot is now available.");
  }
  function statusLabel(appointment: Appointment) {
    if (appointment.queue_entry) return appointment.queue_entry.status;
    if (appointment.status === "no_show") return "No-show (confirmed by staff)";
    if (appointment.status === "booked" && new Date(appointment.scheduled_at).getTime() + 30 * 60000 <= Date.now()) return "Overdue — awaiting staff decision";
    return appointment.status.replace("_", " ");
  }
  return <main className="staff-page admin-page">
    <header className="dashboard-header"><div><p className="eyebrow">QUEUEWISE</p><strong>{staff ? "Staff appointments" : "My appointments"}</strong></div><Link className="secondary-button" to={staff ? "/staff" : "/dashboard"}>Back to queue dashboard</Link></header>
    <section className="staff-content">
      <div className="welcome-block"><p className="eyebrow">APPOINTMENTS</p><h1>{staff ? "Welcome scheduled customers." : "Plan your visit."}</h1>
        <p className="supporting-text">Monday–Friday, 8 AM–5 PM Philippine time. Slots are 30 minutes, up to 30 days ahead. Staff check-in is required. Due appointments get the next available turn without interrupting a customer already called or being served.</p>
      </div>
      <div className="admin-card-actions"><button className="secondary-button" disabled={busy} aria-pressed={overview} onClick={() => { setOverview(true); setHistory(false); setPage(1); setEditing(null); }}>Today & upcoming</button><button className="secondary-button" disabled={busy} aria-pressed={!history && !overview} onClick={() => { setHistory(false); setOverview(false); setPage(1); setEditing(null); }}>{staff ? "By date" : "Book / by date"}</button><button className="secondary-button" disabled={busy} aria-pressed={history} onClick={() => { setHistory(true); setOverview(false); setPage(1); setEditing(null); }}>Appointment history</button></div>
      <div className="history-filters">{!history && !overview && <label className="form-field"><span>Visit date</span><input type="date" value={date} disabled={busy} onChange={(event) => { setDate(event.target.value); setEditing(null); setHistory(false); setSuccess(""); setError(""); }} /></label>}<button className="secondary-button" disabled={busy} onClick={() => setRefresh((value) => value + 1)}>Refresh</button></div>
      {[error, loadError, serviceError].filter(Boolean).map((message, index) => <p className="form-error" role="alert" key={index}>{message}</p>)}
      {success && <p className="form-success" role="status">{success}</p>}
      {editing && <AppointmentRescheduleForm key={editing.id} appointment={editing} busy={busy} onSave={saveReschedule} onCancel={() => setEditing(null)} />}
      {!staff && !history && !overview && !editing && <form className="admin-service-form" onSubmit={book}><h2>Book a visit</h2><fieldset disabled={busy}>
        <label className="form-field"><span>Service</span><select required value={serviceId} onChange={(event) => setServiceId(event.target.value)}><option value="">Choose a service</option>{services.map((service) => <option key={service.id} value={service.id}>{service.name}</option>)}</select></label>
        <label className="form-field"><span>Available time (Philippine time)</span><select required value={time} onChange={(event) => setTime(event.target.value)}><option value="">Choose a time</option>{slots.map((slot) => <option key={slot} value={slot}>{slot}</option>)}</select></label>
        {slotError && <p className="form-error" role="alert">{slotError}</p>}
        {serviceId && slots.length === 0 && !slotError && <p className="supporting-text">No available slots loaded. Choose another date or refresh.</p>}
        <button className="primary-button" disabled={!time || !date} type="submit">{busy ? "Saving..." : "Book appointment"}</button>
      </fieldset></form>}
      <h2>{history ? "Appointment history" : overview ? "Today & upcoming appointments" : `Appointments · ${date || "Choose a date"}`}</h2>
      {overview && <p className="supporting-text">Today’s appointments and future bookings, earliest first. Past visits are available in Appointment history.</p>}
      {history && <p className="supporting-text">Completed, cancelled, skipped, confirmed no-shows, and overdue bookings awaiting a staff decision. Newest visit dates first.</p>}
      <p className="supporting-text">Updates every five seconds. Early check-ins wait until their booked time. After check-in, queue actions control the visit’s outcome.</p>
      {!loaded && !loadError && <p role="status">{date ? "Loading appointments..." : "Choose a date to view appointments."}</p>}
      {loaded && appointments.length === 0 && <p>{history ? "No appointment history on this page." : overview ? "No appointments today or upcoming on this page." : "No appointments for this date."}</p>}
      <div className="admin-service-list">{appointments.map((appointment) => <article className="admin-service-card" key={appointment.id}>
        <h3>{appointment.service.name}</h3><p>{appointmentTime(appointment.scheduled_at)} PHT{staff ? ` · ${appointment.user.name}` : ""}</p>
        <p>Status: <strong>{statusLabel(appointment)}</strong>{appointment.queue_entry && ` · Queue #${appointment.queue_entry.queue_number}`}</p>
        {!staff && appointment.status === "booked" && new Date(appointment.scheduled_at).getTime() > Date.now() && <button className="secondary-button" disabled={busy} onClick={() => { setEditing(appointment); setError(""); setSuccess(""); }}>Reschedule</button>}
        {staff && appointment.status === "booked" && new Date(appointment.scheduled_at).getTime() + 30 * 60000 <= Date.now() && <button className="secondary-button" disabled={busy} onClick={() => { if (window.confirm(`Mark ${appointment.user.name} as a no-show for ${appointmentTime(appointment.scheduled_at)}?`)) void act(() => markNoShow(appointment.id), "Appointment marked as no-show."); }}>Mark no-show</button>}
        {appointment.status === "booked" && <button className="secondary-button" disabled={busy || (staff && new Intl.DateTimeFormat("en-CA", { timeZone: "Asia/Manila" }).format(new Date(appointment.scheduled_at)) !== todayInManila())} onClick={() => void act(() => staff ? checkInAppointment(appointment.id) : cancelAppointment(appointment.id), staff ? "Checked in. The queue dashboard will show this customer." : "Appointment cancelled.")}>{staff ? "Check in customer" : "Cancel appointment"}</button>}
      </article>)}</div>
      {(history || overview) && loaded && <div className="history-pagination"><button className="secondary-button" disabled={busy || page <= 1} onClick={() => setPage(page - 1)}>Previous</button><span>Page {page} of {lastPage}</span><button className="secondary-button" disabled={busy || page >= lastPage} onClick={() => setPage(page + 1)}>Next</button></div>}
    </section>
  </main>;
}
