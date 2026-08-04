import api from "../lib/api";

export type StaffQueueStatus =
  | "waiting"
  | "called"
  | "serving"
  | "completed"
  | "cancelled"
  | "skipped";

export type StaffQueueEntry = {
  id: number;

  queue_number: number;
  queue_date: string;
  status: StaffQueueStatus;

  joined_at: string;
  called_at: string | null;
  completed_at: string | null;

  user: {
    id: number;
    name: string;
    email: string;
  };

  service: {
    id: number;
    name: string;
    average_service_minutes: number;
  };
};

type QueueResponse = {
  message: string;
  queue_entry: StaffQueueEntry;
};

export async function getStaffQueue():
  Promise<StaffQueueEntry[]> {
  const response = await api.get<{
    queue_entries: StaffQueueEntry[];
  }>("/api/staff/queue");

  return response.data.queue_entries;
}

export async function callNextCustomer(
  serviceId: number,
): Promise<StaffQueueEntry> {
  const response =
    await api.post<QueueResponse>(
      "/api/staff/queue/call-next",
      {
        service_id: serviceId,
      },
    );

  return response.data.queue_entry;
}

export async function completeCustomer(
  queueEntryId: number,
): Promise<StaffQueueEntry> {
  const response =
    await api.patch<QueueResponse>(
      `/api/staff/queue/${queueEntryId}/complete`,
    );

  return response.data.queue_entry;
}