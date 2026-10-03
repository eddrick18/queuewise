import { useEffect, useState, type FormEvent } from "react";
import { getSlots, type Appointment } from "../services/appointmentService";
import { getErrorMessage } from "../lib/getErrorMessage";

type Props = { appointment: Appointment; busy: boolean; onSave: (date: string, time: string) => void; onCancel: () => void };

export default function AppointmentRescheduleForm({ appointment, busy, onSave, onCancel }: Props) {
  const [date, setDate] = useState("");
  const [time, setTime] = useState("");
  const [slots, setSlots] = useState<string[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [retry, setRetry] = useState(0);
  useEffect(() => {
    const controller = new AbortController();
    setSlots([]); setTime(""); setError(""); setLoading(Boolean(date));
    if (date) {
      getSlots(date, String(appointment.service.id), controller.signal)
        .then((result) => { if (!controller.signal.aborted) setSlots(result); })
        .catch((failure) => { if (!controller.signal.aborted) setError(getErrorMessage(failure)); })
        .finally(() => { if (!controller.signal.aborted) setLoading(false); });
    }
    return () => controller.abort();
  }, [date, appointment.service.id, retry]);
  function submit(event: FormEvent<HTMLFormElement>) { event.preventDefault(); onSave(date, time); }
  return <form className="admin-service-form" onSubmit={submit}>
    <h2>Reschedule {appointment.service.name}</h2>
    <p className="supporting-text">Your original booking is kept until the new slot is successfully reserved. All times are Philippine time.</p>
    <fieldset disabled={busy}>
      <label className="form-field"><span>New date</span><input type="date" required value={date} onChange={(event) => setDate(event.target.value)} /></label>
      <label className="form-field"><span>New time</span><select required value={time} onChange={(event) => setTime(event.target.value)}><option value="">Choose a time</option>{slots.map((slot) => <option key={slot} value={slot}>{slot}</option>)}</select></label>
      {loading && <p role="status">Loading available slots...</p>}
      {error && <p role="alert" className="form-error">{error}</p>}
      {date && !loading && !error && slots.length === 0 && <p>No available slots. Try another date.</p>}
      <div className="admin-card-actions"><button className="primary-button" disabled={!time || loading} type="submit">{busy ? "Saving..." : "Save new time"}</button><button className="secondary-button" type="button" onClick={() => setRetry((value) => value + 1)}>Refresh slots</button><button className="secondary-button" type="button" onClick={onCancel}>Keep original booking</button></div>
    </fieldset>
  </form>;
}
