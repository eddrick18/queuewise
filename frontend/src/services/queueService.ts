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

  priority_at: string | null;
  people_ahead: number | null;
  estimated_wait_minutes: number | null;

  service: {
    id: number;
    name: string;
    average_service_minutes: number;
  };
};

/**
 * Load all active services from Laravel.
 */
export async function getServices(): Promise<Service[]> {
  const response = await api.get<{
    services: Service[];
  }>("/api/services");

  return response.data.services;
}

/**
 * Load the customer's latest queue record for today.
 */
export async function getCurrentQueue():
  Promise<QueueEntry | null> {
  const response = await api.get<{
    queue_entry: QueueEntry | null;
  }>("/api/queue/current");

  return response.data.queue_entry;
}

/**
 * Join a selected service queue.
 */
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

/**
 * Cancel a waiting queue entry.
 */
export async function cancelQueue(
  queueEntryId: number,
): Promise<QueueEntry> {
  const response = await api.patch<{
    message: string;
    queue_entry: QueueEntry;
  }>(
    `/api/queue/${queueEntryId}/cancel`,
  );

  return response.data.queue_entry;
}
