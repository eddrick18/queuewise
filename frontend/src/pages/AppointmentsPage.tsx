import { useEffect, useRef, useState, type FormEvent } from "react";
import { Link } from "react-router-dom";
import { useAuth } from "../context/AuthContext";
import { getErrorMessage } from "../lib/getErrorMessage";
import { getServices, type Service } from "../services/queueService";
import { bookAppointment, cancelAppointment, checkInAppointment, getAppointments, getSlots, type Appointment } from "../services/appointmentService";
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
      if (fetching || operation.current || !date) return;
      fetching = true;
      const version = revision.current;
      try {
        const data = await getAppointments(date, staff, controller.signal);
        if (!controller.signal.aborted && version === revision.current) { setAppointments(data); setLoaded(true); setLoadError(""); }
      } catch (failure) {
        if (!controller.signal.aborted && version === revision.current) { setLoadError(getErrorMessage(failure)); setAppointments([]); setLoaded(false); }
      } finally { fetching = false; }
    }
    void load();
    const timer = window.setInterval(() => void load(), 5000);
    return () => { controller.abort(); window.clearInterval(timer); };
  }, [date, staff, refresh]);

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

  return <main className="staff-page admin-page">
    <header className="dashboard-header"><div><p className="eyebrow">QUEUEWISE</p><strong>{staff ? "Staff appointments" : "My appointments"}</strong></div><Link className="secondary-button" to={staff ? "/staff" : "/dashboard"}>Back to queue dashboard</Link></header>
    <section className="staff-content">
      <div className="welcome-block"><p className="eyebrow">APPOINTMENTS</p><h1>{staff ? "Welcome scheduled customers." : "Plan your visit."}</h1>
        <p className="supporting-text">Monday–Friday, 8 AM–5 PM Philippine time. Slots are 30 minutes, up to 30 days ahead. Staff check-in is required. Due appointments get the next available turn without interrupting a customer already called or being served.</p>
      </div>
      <div className="history-filters"><label className="form-field"><span>Visit date</span><input type="date" value={date} disabled={busy} onChange={(event) => { setDate(event.target.value); setSuccess(""); setError(""); }} /></label><button className="secondary-button" disabled={busy} onClick={() => setRefresh((value) => value + 1)}>Refresh</button></div>
      {[error, loadError, serviceError].filter(Boolean).map((message, index) => <p className="form-error" role="alert" key={index}>{message}</p>)}
      {success && <p className="form-success" role="status">{success}</p>}
      {!staff && <form className="admin-service-form" onSubmit={book}><h2>Book a visit</h2><fieldset disabled={busy}>
        <label className="form-field"><span>Service</span><select required value={serviceId} onChange={(event) => setServiceId(event.target.value)}><option value="">Choose a service</option>{services.map((service) => <option key={service.id} value={service.id}>{service.name}</option>)}</select></label>
        <label className="form-field"><span>Available time (Philippine time)</span><select required value={time} onChange={(event) => setTime(event.target.value)}><option value="">Choose a time</option>{slots.map((slot) => <option key={slot} value={slot}>{slot}</option>)}</select></label>
        {slotError && <p className="form-error" role="alert">{slotError}</p>}
        {serviceId && slots.length === 0 && !slotError && <p className="supporting-text">No available slots loaded. Choose another date or refresh.</p>}
        <button className="primary-button" disabled={!time || !date} type="submit">{busy ? "Saving..." : "Book appointment"}</button>
      </fieldset></form>}
      <h2>Appointments · {date || "Choose a date"}</h2>
      <p className="supporting-text">Updates every five seconds. Early check-ins wait until their booked time. After check-in, queue actions control the visit’s outcome.</p>
      {!loaded && !loadError && <p role="status">{date ? "Loading appointments..." : "Choose a date to view appointments."}</p>}
      {loaded && appointments.length === 0 && <p>No appointments for this date.</p>}
      <div className="admin-service-list">{appointments.map((appointment) => <article className="admin-service-card" key={appointment.id}>
        <h3>{appointment.service.name}</h3><p>{appointmentTime(appointment.scheduled_at)} PHT{staff ? ` · ${appointment.user.name}` : ""}</p>
        <p>Status: <strong>{appointment.queue_entry?.status || (appointment.status === "booked" && date < todayInManila() ? "Missed (not checked in)" : appointment.status.replace("_", " "))}</strong>{appointment.queue_entry && ` · Queue #${appointment.queue_entry.queue_number}`}</p>
        {appointment.status === "booked" && <button className="secondary-button" disabled={busy || (staff && date !== todayInManila())} onClick={() => void act(() => staff ? checkInAppointment(appointment.id) : cancelAppointment(appointment.id), staff ? "Checked in. The queue dashboard will show this customer." : "Appointment cancelled.")}>{staff ? "Check in customer" : "Cancel appointment"}</button>}
      </article>)}</div>
    </section>
  </main>;
}
