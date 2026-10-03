import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { getUpcomingAppointments, type Appointment } from "../services/appointmentService";

export default function NextAppointment() {
  const [appointment, setAppointment] = useState<Appointment | null>(null);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);
  useEffect(() => {
    const controller = new AbortController();
    let fetching = false;
    async function load() {
      if (fetching) return;
      fetching = true;
      try {
        let page = 1;
        let next: Appointment | undefined;
        while (!controller.signal.aborted) {
          const result = await getUpcomingAppointments(false, page, controller.signal);
          next = result.data.find((item) => item.status === "booked" && new Date(item.scheduled_at).getTime() >= Date.now());
          if (next || page >= result.last_page) break;
          page += 1;
        }
        if (!controller.signal.aborted) { setAppointment(next ?? null); setFailed(false); }
      } catch {
        if (!controller.signal.aborted) { setFailed(true); setAppointment(null); }
      } finally {
        fetching = false;
        if (!controller.signal.aborted) setLoading(false);
      }
    }
    void load();
    const timer = window.setInterval(() => void load(), 5000);
    return () => { controller.abort(); window.clearInterval(timer); };
  }, []);
  return <section className="next-appointment" aria-label="Next appointment">
    <div><p className="eyebrow">Next appointment</p>
      <h2>{loading ? "Loading your next visit…" : failed ? "Appointment details unavailable" : appointment?.service.name ?? "Plan your next visit"}</h2>
      <p className="supporting-text">{appointment ? `${new Date(appointment.scheduled_at).toLocaleString("en-PH", { timeZone: "Asia/Manila", dateStyle: "medium", timeStyle: "short" })} · Philippine time` : failed ? "Open My appointments to check your bookings." : "Book a time that works for you, or join a queue below."}</p>
    </div><Link className="secondary-button" to="/appointments">{appointment ? "View appointment" : "My appointments"}</Link>
  </section>;
}
