import api from "../lib/api";

export type Appointment = {
  id: number;
  scheduled_at: string;
  status: "booked" | "cancelled" | "checked_in" | "no_show";
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
export async function rescheduleAppointment(id: number, date: string, time: string) { await api.patch(`/api/appointments/${id}/reschedule`, { date, time }); }
export async function markNoShow(id: number) { await api.patch(`/api/staff/appointments/${id}/no-show`); }
export async function getAppointmentHistory(staff: boolean, page: number, signal: AbortSignal) {
  return (await api.get<{ appointments: { data: Appointment[]; current_page: number; last_page: number; total: number } }>(staff ? "/api/staff/appointments/history" : "/api/appointments/history", { params: { page }, signal })).data.appointments;
}
export async function checkInAppointment(id: number) { await api.patch(`/api/staff/appointments/${id}/check-in`); }

export async function getUpcomingAppointments(staff: boolean, page: number, signal: AbortSignal) {
  return (await api.get<{ appointments: { data: Appointment[]; current_page: number; last_page: number; total: number } }>(staff ? "/api/staff/appointments/upcoming" : "/api/appointments/upcoming", { params: { page }, signal })).data.appointments;
}
