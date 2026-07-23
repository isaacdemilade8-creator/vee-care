import { useMutation, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { endpoints } from '../services/endpoints';

export function usePublishPost(onSuccess?: () => void) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: { title: string; body: string; image?: File | null }) => {
      let imageUrl: string | undefined;

      if (payload.image?.size) {
        const upload = new FormData();
        upload.append('folder', 'posts');
        upload.append('image', payload.image);
        const response = await endpoints.uploadImage(upload);
        imageUrl = response.data.url;
      }

      return endpoints.createPost({
        title: payload.title,
        body: payload.body,
        image_url: imageUrl,
      });
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['posts'] });
      toast.success('Post published');
      onSuccess?.();
    },
  });
}
