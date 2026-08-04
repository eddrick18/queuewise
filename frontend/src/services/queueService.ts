import api from "../lib/api";

export type Service = {
  id: number;
  name: string;
  description: string | null;
  average_service_minutes: number;
  is_active: boolean;
};

export type QueueStatus =
  | "waiting"
  | "called"
  | "serving"
  | "completed"
  | "cancelled"
  | "skipped";

export type QueueEntry = {
  id: number;
  queue_number: number;
  queue_date: string;
  status: QueueStatus;
  joined_at: string;
  called_at: string | null;
  completed_at: string | null;
  people_ahead: number;
  estimated_wait_minutes: number;

  service: {
    id: number;
    name: string;
    average_service_minutes: number;
  };
};

export async function getServices(): Promise<Service[]> {
  const response = await api.get<{
    services: Service[];
  }>("/api/services");

  return response.data.services;
}

export async function getCurrentQueue():
  Promise<QueueEntry | null> {
  const response = await api.get<{
    queue_entry: QueueEntry | null;
  }>("/api/queue/current");

  return response.data.queue_entry;
}

export async function joinQueue(
  serviceId: number,
): Promise<QueueEntry> {
  const response = await api.post<{
    message: string;
    queue_entry: QueueEntry;
  }>("/api/queue/join", {
    service_id: serviceId,
  });

  return response.data.queue_entry;
}