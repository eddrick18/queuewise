import api from "../lib/api";

export type HistoryStatus = "completed" | "cancelled" | "skipped";
export type HistoryFilters = { date: string; service_id: string; status: string };
export type QueueHistory = {
  date: string;
  summary: { total: number; active: number; completed: number; cancelled: number; skipped: number };
  entries: {
    data: {
      id: number;
      queue_number: number;
      queue_date: string;
      status: HistoryStatus;
      customer_name: string | null;
      service: { id: number; name: string; is_active: boolean } | null;
      joined_at: string | null;
      called_at: string | null;
      completed_at: string | null;
    }[];
    current_page: number;
    last_page: number;
    total: number;
  };
};

export async function getQueueHistory(filters: HistoryFilters, page: number, signal: AbortSignal): Promise<QueueHistory> {
  const response = await api.get<QueueHistory>("/api/admin/queue-history", {
    params: { date: filters.date || undefined, service_id: filters.service_id || undefined, status: filters.status || undefined, page },
    signal,
  });
  return response.data;
}
