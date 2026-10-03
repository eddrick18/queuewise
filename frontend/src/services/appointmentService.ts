import api from "../lib/api";

export type Appointment = {
  id: number;
  scheduled_at: string;
  status: "booked" | "cancelled" | "checked_in";
  service: { id: number; name: string };
  user: { id: number; name: string };
  queue_entry: { id: number; status: string; queue_number: number } | null;
};

export async function getAppointments(date: string, staff: boolean, signal: AbortSignal) {
  return (await api.get<{ appointments: Appointment[] }>(staff ? "/api/staff/appointments" : "/api/appointments", { params: { date }, signal })).data.appointments;
}
export async function getSlots(date: string, serviceId: string, signal: AbortSignal) {
  return (await api.get<{ slots: string[] }>("/api/appointments/slots", { params: { date, service_id: serviceId }, signal })).data.slots;
}
export async function bookAppointment(date: string, time: string, serviceId: string) {
  await api.post("/api/appointments", { date, time, service_id: serviceId });
}
export async function cancelAppointment(id: number) { await api.patch(`/api/appointments/${id}/cancel`); }
export async function checkInAppointment(id: number) { await api.patch(`/api/staff/appointments/${id}/check-in`); }
